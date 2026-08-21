<?php

use App\Enums\PlateDirection;
use App\Enums\WatchlistKind;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;
use App\Models\WatchlistPlate;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'North entrance']);
    $this->exitCamera = Camera::factory()->for($this->site)->exit()->create(['name' => 'South exit']);
});

it('opens for an owner and lists every plate detection in the default 7-day window', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'JD45GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subHours(2),
    ]);
    PlateEvent::factory()->for($this->exitCamera)->create([
        'plate_number' => 'JD45GP',
        'direction' => PlateDirection::Out,
        'captured_at' => Date::now()->subHour(),
    ]);

    Livewire::test('pages::activity')
        ->assertSee('JD 45 GP')
        ->assertSee('North entrance')
        ->assertSee('South exit');
});

it('opens for a security operator', function () {
    actingAsTenant(User::factory()->securityOperator($this->owner)->create());

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'JD45GP',
        'captured_at' => Date::now()->subHours(2),
    ]);

    Livewire::test('pages::activity')->assertOk()->assertSee('JD 45 GP');
});

it('refuses access to shop accounts through the route', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    // Shop needs a subscription so the `subscribed` middleware doesn't
    // pre-empt the role check with a paywall redirect. What we're
    // actually verifying here is the role guard.
    ShopSubscription::factory()->for($shop, 'organization')->create();
    $this->actingAs(User::factory()->shopAdmin($shop)->create());

    $this->get(route('activity'))->assertForbidden();
});

it('excludes events from outside the tenant sites', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $foreignSite = Site::factory()->create();
    $foreignCamera = Camera::factory()->for($foreignSite)->entrance()->create();
    PlateEvent::factory()->for($foreignCamera)->create([
        'plate_number' => 'FOREIGN1',
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'MINE1GP',
        'captured_at' => Date::now()->subHour(),
    ]);

    Livewire::test('pages::activity')
        ->assertSee('MINE1GP')
        ->assertDontSee('FOREIGN1');
});

it('narrows to a single camera when a filter is applied', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    // A pinned site is required because the camera filter is only kept
    // when the picked id belongs to the current site's active cameras.
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'CAMONE01',
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->exitCamera)->create([
        'plate_number' => 'CAMTWO01',
        'captured_at' => Date::now()->subHour(),
    ]);

    Livewire::test('pages::activity')
        ->assertSee('CAMONE01')
        ->assertSee('CAMTWO01')
        ->set('cameraId', $this->camera->id)
        ->assertSee('CAMONE01')
        ->assertDontSee('CAMTWO01');
});

it('hides the camera filter for a single-camera site', function () {
    $solo = Site::factory()->for_($this->owner)->create(['name' => 'Mall Solo']);
    Camera::factory()->for($solo)->entrance()->create(['name' => 'Only camera']);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($solo->id);

    Livewire::test('pages::activity')
        ->assertSet('cameraId', null)
        ->assertSee('every plate detection')
        // Site has one camera, so the "All cameras" option is not rendered.
        ->assertDontSee('All cameras');
});

it('shows the camera filter when the site has multiple cameras', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    Livewire::test('pages::activity')->assertSee('All cameras');
});

it('drops a camera filter that does not belong to the current site', function () {
    // Camera on a *different* owner-site, so it isn't in the current site's
    // dropdown. A tampered URL that pins it should be silently dropped
    // rather than leaking a lookup outside the site switcher.
    $second = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);
    $foreignCamera = Camera::factory()->for($second)->entrance()->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    Livewire::withQueryParams(['camera' => $foreignCamera->id])
        ->test('pages::activity')
        ->assertSet('cameraId', null);
});

it('filters by a plate substring in either form', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'JD45GP',
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'KT07GP',
        'captured_at' => Date::now()->subHour(),
    ]);

    Livewire::test('pages::activity')
        // Spaces and lower case should still find the plate.
        ->set('plateSearch', 'jd 45')
        ->assertSee('JD 45 GP')
        ->assertDontSee('KT 07 GP');
});

it('drills into a single plate history via focusOnPlate', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'FOCUS1GP',
        'direction' => PlateDirection::In,
        'captured_at' => Date::now()->subHours(3),
    ]);
    PlateEvent::factory()->for($this->exitCamera)->create([
        'plate_number' => 'FOCUS1GP',
        'direction' => PlateDirection::Out,
        'captured_at' => Date::now()->subHour(),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'OTHER01',
        'captured_at' => Date::now()->subHour(),
    ]);

    Livewire::test('pages::activity')
        ->call('focusOnPlate', 'FOCUS1GP')
        ->assertSet('plateSearch', 'FOCUS1GP')
        ->assertSee('Plate history')
        ->assertSee('FOCUS 1 GP')
        ->assertDontSee('OTHER01');
});

it('excludes events outside the selected date range', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'OLDXX1GP',
        'captured_at' => Date::now()->subDays(30),
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'RECENT1GP',
        'captured_at' => Date::now()->subHour(),
    ]);

    Livewire::test('pages::activity')
        ->assertSee('RECENT1GP')
        ->assertDontSee('OLDXX1GP');
});

it('watches the currently-focused plate from the header action', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    Livewire::test('pages::activity')
        ->call('focusOnPlate', 'WATCHX1')
        ->call('watchFocusedPlate');

    $entry = WatchlistPlate::sole();

    expect($entry->plate_number)->toBe('WATCHX1')
        ->and($entry->kind)->toBe(WatchlistKind::Watch)
        ->and($entry->site_id)->toBe($this->site->id);
});
