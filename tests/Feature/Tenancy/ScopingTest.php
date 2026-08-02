<?php

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Tenancy;

/**
 * Two owners, one of them with two sites, plus two shops inside the first
 * site, and traffic data on both owners' sites. Every scoping assertion below
 * reads from this fixture.
 */
beforeEach(function () {
    $this->ownerA = Organization::factory()->owner()->create(['name' => 'Owner A']);
    $this->ownerB = Organization::factory()->owner()->create(['name' => 'Owner B']);

    $this->siteA = Site::factory()->for_($this->ownerA)->create(['name' => 'Mall A']);
    $this->siteA2 = Site::factory()->for_($this->ownerA)->create(['name' => 'Mall A2']);
    $this->siteB = Site::factory()->for_($this->ownerB)->create(['name' => 'Mall B']);

    $this->shop1 = Organization::factory()->shop($this->siteA)->create();
    $this->shop2 = Organization::factory()->shop($this->siteA)->create();

    $this->cameraA = Camera::factory()->for($this->siteA)->create();
    $this->cameraB = Camera::factory()->for($this->siteB)->create();

    PlateEvent::factory()->for($this->cameraA)->create();
    PlateEvent::factory()->for($this->cameraB)->create();

    Visit::factory()->for($this->siteA)->create();
    Visit::factory()->for($this->siteB)->create();
});

it('limits an owner admin to their own sites', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->ownerA)->create());

    expect(Site::pluck('name')->all())->toEqualCanonicalizing(['Mall A', 'Mall A2'])
        ->and(Camera::pluck('id')->all())->toEqual([$this->cameraA->id])
        ->and(Visit::count())->toBe(1)
        ->and(PlateEvent::count())->toBe(1);
});

it('limits a shop user to the single site it trades in', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop1)->create());

    expect(Site::pluck('name')->all())->toEqual(['Mall A'])
        ->and(Camera::pluck('id')->all())->toEqual([$this->cameraA->id])
        ->and(Visit::count())->toBe(1)
        ->and(PlateEvent::count())->toBe(1);
});

it('leaves a platform admin unscoped', function () {
    actingAsTenant(User::factory()->platformAdmin()->create());

    expect(Site::count())->toBe(3)
        ->and(Camera::count())->toBe(2)
        ->and(Visit::count())->toBe(2)
        ->and(PlateEvent::count())->toBe(2);
});

it('narrows queries to the selected site when the switcher is used', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->ownerA)->create());

    Camera::factory()->for($this->siteA2)->create();

    expect(Camera::count())->toBe(2);

    app(Tenancy::class)->setCurrentSiteId($this->siteA2->id);

    expect(Camera::pluck('site_id')->all())->toEqual([$this->siteA2->id]);
});

it('ignores a switcher value for a site the user cannot reach', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->ownerA)->create());

    app(Tenancy::class)->setCurrentSiteId($this->siteB->id);

    expect(app(Tenancy::class)->currentSiteId())->toBeNull()
        ->and(Camera::pluck('id')->all())->toEqual([$this->cameraA->id]);
});

it('hides another shop and another owner from a shop user', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop1)->create());

    // A direct lookup by a known foreign key must miss, not just a list query.
    expect(Site::find($this->siteB->id))->toBeNull()
        ->and(Site::find($this->siteA2->id))->toBeNull()
        ->and(Camera::find($this->cameraB->id))->toBeNull()
        ->and(Visit::where('site_id', $this->siteB->id)->count())->toBe(0);
});

it('refuses plate level data to shop roles', function (UserRole $role, bool $allowed) {
    $organization = $role->isShopRole() ? $this->shop1 : $this->ownerA;

    $user = actingAsTenant(User::factory()->create([
        'organization_id' => $role === UserRole::PlatformAdmin ? null : $organization->getKey(),
        'role' => $role,
    ]));

    $visit = Visit::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteA->id)->first();

    expect($user->can('viewAny', Visit::class))->toBe($allowed)
        ->and($user->can('view', $visit))->toBe($allowed);
})->with([
    [UserRole::OwnerAdmin, true],
    [UserRole::PlatformAdmin, true],
    [UserRole::ShopAdmin, false],
    [UserRole::ShopViewer, false],
]);

it('lets jobs and console work across tenants', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop1)->create());

    expect(Camera::count())->toBe(1)
        ->and(app(Tenancy::class)->withoutScoping(fn () => Camera::count()))->toBe(2);
});

it('creates an owner organization and first site on registration', function () {
    $this->post('/register', [
        'name' => 'Nomsa Dlamini',
        'email' => 'nomsa@example.com',
        'password' => 'password-is-long',
        'password_confirmation' => 'password-is-long',
        'organization_name' => 'Dlamini Properties',
    ])->assertRedirect();

    $user = User::where('email', 'nomsa@example.com')->sole();

    expect($user->role)->toBe(UserRole::OwnerAdmin)
        ->and($user->organization->type)->toBe(OrganizationType::Owner)
        ->and($user->organization->name)->toBe('Dlamini Properties')
        ->and($user->organization->sites()->withoutGlobalScope(SiteScope::class)->count())->toBe(1);
});
