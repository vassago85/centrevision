<?php

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Support\Platform\Impersonation;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Date::setTestNow('2026-09-11 14:00:00');

    $this->owner = Organization::factory()->owner()->create(['name' => 'Blueberry Square']);
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Blueberry Square Main']);
    SiteSubscription::factory()->for($this->site)->create();

    $this->tenant = User::factory()->ownerAdmin($this->owner)->create([
        'name' => 'Nomsa Dlamini',
        'email' => 'nomsa@blueberry.co.za',
    ]);

    $this->admin = User::factory()->platformAdmin()->create([
        'name' => 'Paul Charsley',
        'email' => 'paul@centrevision.co.za',
    ]);
});

it('stamps last_login_at when a real user signs in', function () {
    expect($this->tenant->last_login_at)->toBeNull();

    Event::dispatch(new Login('web', $this->tenant, false));

    $fresh = $this->tenant->fresh();

    expect($fresh->last_login_at)->not->toBeNull()
        ->and($fresh->last_login_at->toDateTimeString())->toBe('2026-09-11 14:00:00');
});

it('does not stamp last_login_at while an admin is impersonating the tenant', function () {
    // Simulate the admin already impersonating the tenant: their Auth::login
    // fires a Login event, and without the guard would smear the admin's
    // click over the tenant's real last-seen timestamp.
    session([Impersonation::SESSION_KEY => $this->admin->getKey()]);
    Auth::login($this->tenant);

    Event::dispatch(new Login('web', $this->tenant, false));

    expect($this->tenant->fresh()->last_login_at)->toBeNull();
});

it('lets a platform admin start viewing as an owner and stops back to themselves', function () {
    actingAsTenant($this->admin);

    $service = app(Impersonation::class);

    $target = $service->start($this->admin, $this->owner);

    expect($target)->not->toBeNull()
        ->and($target->id)->toBe($this->tenant->id)
        ->and(Auth::id())->toBe($this->tenant->id)
        ->and($service->isActive())->toBeTrue()
        ->and($service->impersonatorId())->toBe($this->admin->id);

    $restored = $service->stop();

    expect($restored?->id)->toBe($this->admin->id)
        ->and(Auth::id())->toBe($this->admin->id)
        ->and($service->isActive())->toBeFalse();
});

it('refuses to impersonate for a non-platform-admin caller', function () {
    actingAsTenant($this->tenant);

    $service = app(Impersonation::class);

    expect($service->start($this->tenant, $this->owner))->toBeNull()
        ->and($service->isActive())->toBeFalse();
});

it('returns null when the owner has no owner-admin user to view as', function () {
    $lonely = Organization::factory()->owner()->create(['name' => 'Just an Empty Org']);

    actingAsTenant($this->admin);

    expect(app(Impersonation::class)->start($this->admin, $lonely))->toBeNull();
});

it('routes the start endpoint to /overview as the tenant', function () {
    actingAsTenant($this->admin);

    $this->post(route('platform.impersonate.start', $this->owner))
        ->assertRedirect(route('overview'));

    expect(Auth::id())->toBe($this->tenant->id);
});

it('routes the stop endpoint back to /platform as the real admin', function () {
    actingAsTenant($this->admin);
    app(Impersonation::class)->start($this->admin, $this->owner);

    $this->post(route('platform.impersonate.stop'))
        ->assertRedirect(route('platform.overview'));

    expect(Auth::id())->toBe($this->admin->id);
});

it('blocks non-admins from calling the start endpoint', function () {
    actingAsTenant($this->tenant);

    $this->post(route('platform.impersonate.start', $this->owner))
        ->assertForbidden();
});

it('shop organizations without an owner-admin do not offer a view-as button', function () {
    $summary = new \App\Support\Platform\OwnerSummary(
        organization: $this->owner,
        siteCount: 1,
        cameraCount: 1,
        payingShopCount: 0,
        monthlyCharge: 1800,
        platformShopShare: 0,
        lapsed: false,
        partner: null,
        canImpersonate: false,
    );

    expect($summary->canImpersonate)->toBeFalse();
});
