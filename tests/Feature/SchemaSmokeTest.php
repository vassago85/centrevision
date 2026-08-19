<?php

use App\Enums\BaseTier;
use App\Enums\CameraRole;
use App\Enums\OrganizationType;
use App\Enums\PlateTagType;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PlateEvent;
use App\Models\PlateTag;
use App\Models\ShopInvitation;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\UniqueConstraintViolationException;

it('builds the full tenancy hierarchy', function () {
    $partner = Partner::factory()->create();

    $owner = Organization::factory()->owner()->referredBy($partner)->create();
    $siteA = Site::factory()->for_($owner)->create(['name' => 'Garsfontein Junction']);
    $siteB = Site::factory()->for_($owner)->create(['name' => 'Menlyn Corner']);

    $shop = Organization::factory()->shop($siteA)->create();

    expect($owner->type)->toBe(OrganizationType::Owner)
        ->and($owner->sites)->toHaveCount(2)
        ->and($owner->referredByPartner->is($partner))->toBeTrue()
        ->and($shop->isShop())->toBeTrue()
        ->and($shop->parentSite->is($siteA))->toBeTrue()
        ->and($siteA->shops)->toHaveCount(1)
        ->and($siteB->shops)->toHaveCount(0)
        ->and($partner->organizations)->toHaveCount(1);

    // A shop inherits commission from the owner of the site it trades in.
    expect($shop->commissionPartner()->is($partner))->toBeTrue();
});

it('assigns users a role and mirrors it into a spatie role', function () {
    $owner = Organization::factory()->owner()->create();
    $user = User::factory()->ownerAdmin($owner)->create();

    expect($user->role)->toBe(UserRole::OwnerAdmin)
        ->and($user->organization->is($owner))->toBeTrue()
        ->and($user->hasRole(UserRole::OwnerAdmin->value))->toBeTrue();

    $user->update(['role' => UserRole::ShopViewer]);

    expect($user->fresh()->hasRole(UserRole::ShopViewer->value))->toBeTrue()
        ->and($user->fresh()->hasRole(UserRole::OwnerAdmin->value))->toBeFalse();
});

it('encrypts camera ISAPI passwords at rest', function () {
    $camera = Camera::factory()->entrance()->create(['isapi_password' => 'super-secret']);

    expect($camera->fresh()->isapi_password)->toBe('super-secret');

    $raw = DB::table('cameras')->where('id', $camera->id)->value('isapi_password');

    expect($raw)->not->toBe('super-secret')
        ->and($camera->role)->toBe(CameraRole::Entrance)
        ->and($camera->role->impliedDirection()->value)->toBe('in');
});

it('stores plate events and pairs them into visits', function () {
    $site = Site::factory()->create();
    $camera = Camera::factory()->entrance()->create(['site_id' => $site->id]);

    $entry = PlateEvent::factory()->for($camera)->entering()->plateNumber('JD45GP')->create();
    $exit = PlateEvent::factory()->for($camera)->exiting()->plateNumber('JD45GP')->create();

    $visit = Visit::create([
        'site_id' => $site->id,
        'plate_number' => 'JD45GP',
        'entry_event_id' => $entry->id,
        'exit_event_id' => $exit->id,
        'entered_at' => $entry->captured_at,
        'exited_at' => $exit->captured_at,
        'dwell_minutes' => 46,
        'status' => VisitStatus::Closed,
    ]);

    expect($visit->entryEvent->is($entry))->toBeTrue()
        ->and($visit->exitEvent->is($exit))->toBeTrue()
        ->and($visit->status)->toBe(VisitStatus::Closed)
        ->and($site->visits)->toHaveCount(1)
        ->and($site->plateEvents)->toHaveCount(2);
});

it('rejects duplicate captures of the same plate on one camera', function () {
    $camera = Camera::factory()->create();
    $at = now()->startOfSecond();

    PlateEvent::factory()->for($camera)->plateNumber('HK12GP')->at($at)->create();

    expect(fn () => PlateEvent::factory()->for($camera)->plateNumber('HK12GP')->at($at)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('excludes recurring-pattern plates from shopper metrics', function () {
    $site = Site::factory()->create();

    Visit::factory()->for($site)->plateNumber('SHOPPER1GP')->create();
    Visit::factory()->for($site)->plateNumber('STAFF11GP')->create();

    PlateTag::factory()->recurring()->create([
        'site_id' => $site->id,
        'plate_number' => 'STAFF11GP',
    ]);

    expect(Visit::query()->count())->toBe(2)
        ->and(Visit::query()->excludingRecurring()->count())->toBe(1)
        ->and(Visit::query()->excludingRecurring()->first()->plate_number)->toBe('SHOPPER1GP')
        ->and(PlateTag::query()->ofType(PlateTagType::RecurringPattern)->count())->toBe(1);
});

it('supports the billing and partner tables', function () {
    $partner = Partner::factory()->create(['commission_rate' => 0.25]);
    $owner = Organization::factory()->owner()->referredBy($partner)->create();
    $site = Site::factory()->for_($owner)->create();
    $shop = Organization::factory()->shop($site)->create();

    $siteSub = SiteSubscription::factory()->tier(BaseTier::Standard)->cappedAt(2500)->create([
        'site_id' => $site->id,
        'partner_id' => $partner->id,
    ]);
    $shopSub = ShopSubscription::factory()->create(['organization_id' => $shop->id]);

    $invitation = ShopInvitation::factory()->create(['site_id' => $site->id]);

    expect($siteSub->base_tier)->toBe(BaseTier::Standard)
        ->and((float) $siteSub->base_fee)->toBe(3200.00)
        ->and((float) $siteSub->variable_fee_cap)->toBe(2500.00)
        ->and($siteSub->partner_id)->toBe($partner->id)
        ->and($site->subscription->is($siteSub))->toBeTrue()
        ->and($shop->shopSubscription->is($shopSub))->toBeTrue()
        ->and($invitation->isPending())->toBeTrue()
        ->and((float) $partner->commission_rate)->toBe(0.25);
});
