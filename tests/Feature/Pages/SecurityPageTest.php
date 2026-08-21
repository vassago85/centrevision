<?php

use App\Enums\VisitStatus;
use App\Enums\WatchlistKind;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WatchlistPlate;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create([
        'name' => 'Mall A',
        'settings' => ['dwell_alert_hours' => 4],
    ]);
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'North entrance']);
    // The Security page filters dwell-based alerts to sites with exit
    // tracking, so add an exit camera here to keep the suite exercising
    // the "with exit" path. Entry-only sites are covered by their own test.
    Camera::factory()->for($this->site)->exit()->create(['name' => 'South exit']);

    $this->user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

function openVisit(Site $site, string $plate, int $hoursAgo): Visit
{
    return Visit::factory()->for($site)->create([
        'plate_number' => $plate,
        'entered_at' => Date::now()->subHours($hoursAgo),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);
}

it('defaults to the threshold configured for the site', function () {
    Livewire::test('pages::security')->assertSet('thresholdHours', 4);
});

it('rejects a threshold that is not on the menu', function () {
    Livewire::withQueryParams(['threshold' => 99])
        ->test('pages::security')
        ->assertSet('thresholdHours', 4);
});

it('lists only vehicles past the chosen threshold', function () {
    openVisit($this->site, 'LONG01GP', 7);
    openVisit($this->site, 'MID001GP', 5);
    openVisit($this->site, 'SHORT1GP', 1);

    Livewire::test('pages::security')
        ->assertSee('LONG01GP')
        ->assertSee('MID001GP')
        ->assertDontSee('SHORT1GP')
        ->set('thresholdHours', 6)
        ->assertSee('LONG01GP')
        ->assertDontSee('MID001GP');
});

it('shows how long each vehicle has been on site', function () {
    openVisit($this->site, 'LONG01GP', 6);

    Livewire::test('pages::security')->assertSee('6h 00m');
});

it('adds a plate to the watchlist without recording anything else about it', function () {
    $visit = openVisit($this->site, 'BX91GP', 7);

    Livewire::test('pages::security')->call('watch', $visit->site_id, $visit->plate_number);

    $entry = WatchlistPlate::sole();

    expect($entry->plate_number)->toBe('BX91GP')
        ->and($entry->kind)->toBe(WatchlistKind::Watch)
        ->and($entry->site_id)->toBe($this->site->id)
        ->and($entry->added_by_user_id)->toBe($this->user->id);
});

it('watching the same plate twice does not duplicate the entry', function () {
    $visit = openVisit($this->site, 'BX91GP', 7);

    Livewire::test('pages::security')
        ->call('watch', $visit->site_id, $visit->plate_number)
        ->call('watch', $visit->site_id, $visit->plate_number);

    expect(WatchlistPlate::count())->toBe(1);
});

it('refuses to tag a plate against another owner site', function () {
    $foreign = Site::factory()->create();

    Livewire::test('pages::security')
        ->call('watch', $foreign->id, 'SNEAKY1GP')
        ->assertForbidden();

    expect(WatchlistPlate::withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

it('shows the camera that caught the vehicle coming in', function () {
    $event = PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'BX91GP',
        'captured_at' => Date::now()->subHours(7),
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'BX91GP',
        'entry_event_id' => $event->id,
        'entered_at' => Date::now()->subHours(7),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    Livewire::test('pages::security')->assertSee('North entrance');
});

it('narrows dwell alerts to a picked camera', function () {
    // Pinning the site is required — the filter is only kept when the
    // picked id belongs to the current site's active cameras.
    app(App\Support\Tenancy::class)->setCurrentSiteId($this->site->id);

    // The site has two entrance cameras. A vehicle over the threshold on
    // each — picking one camera should hide the other's plate.
    $secondEntrance = Camera::factory()->for($this->site)->entrance()->create(['name' => 'West gate']);

    $entryEventA = PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'CAMONE01',
        'captured_at' => Date::now()->subHours(7),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'CAMONE01',
        'entry_event_id' => $entryEventA->id,
        'entered_at' => Date::now()->subHours(7),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    $entryEventB = PlateEvent::factory()->for($secondEntrance)->create([
        'plate_number' => 'CAMTWO01',
        'captured_at' => Date::now()->subHours(7),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'CAMTWO01',
        'entry_event_id' => $entryEventB->id,
        'entered_at' => Date::now()->subHours(7),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    Livewire::test('pages::security')
        ->assertSee('CAMONE01')
        ->assertSee('CAMTWO01')
        ->set('cameraId', $this->camera->id)
        ->assertSee('CAMONE01')
        ->assertDontSee('CAMTWO01');
});

it('offers the camera filter only when the site has multiple cameras', function () {
    // beforeEach already added an entrance + exit camera on the site.
    app(App\Support\Tenancy::class)->setCurrentSiteId($this->site->id);
    Livewire::test('pages::security')->assertSee('All cameras');

    $solo = Site::factory()->for_($this->owner)->create(['name' => 'Mall Solo']);
    Camera::factory()->for($solo)->entrance()->create();
    app(App\Support\Tenancy::class)->setCurrentSiteId($solo->id);

    Livewire::test('pages::security')->assertDontSee('All cameras');
});

it('shows an entry-only notice and suppresses phantom dwell alerts when a site has no exit camera', function () {
    // A separate entry-only site under the same owner, focused via the
    // tenant switcher so the page reads it as the current site.
    $entryOnly = Site::factory()->for_($this->owner)->create(['name' => 'Zentech']);
    Camera::factory()->for($entryOnly)->entrance()->create();

    // A vehicle that entered hours ago would normally be flagged as
    // over-threshold; on an entry-only site it must not be.
    Visit::factory()->for($entryOnly)->create([
        'plate_number' => 'ENTRY01GP',
        'entered_at' => Date::now()->subHours(8),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    app(App\Support\Tenancy::class)->setCurrentSiteId($entryOnly->id);

    Livewire::test('pages::security')
        ->assertSee('This site has no exit camera.')
        ->assertSee('requires exit camera')
        // The plate is not treated as a dwell breach.
        ->assertDontSee('ENTRY01GP');
});
