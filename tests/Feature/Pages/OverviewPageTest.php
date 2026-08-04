<?php

use App\Enums\PlateTagType;
use App\Enums\VisitStatus;
use App\Enums\WatchlistKind;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\PlateTag;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WatchlistPlate;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->shop = Organization::factory()->shop($this->site)->create();
    $this->camera = Camera::factory()->for($this->site)->entrance()->create(['name' => 'North entrance']);

    ShopSubscription::factory()->for($this->shop, 'organization')->create();

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'SHOPPER1',
        'entered_at' => Date::now()->subHours(2),
        'exited_at' => Date::now()->subHours(1),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    // A watchlisted plate that was seen recently — the redesigned dashboard
    // shows plates through the "Recent Watchlist Hits" card, not a generic
    // recent-visits table.
    WatchlistPlate::factory()->watch()->for($this->site)->create([
        'plate_number' => 'HIT001GP',
    ]);
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'HIT001GP',
        'captured_at' => Date::now()->subMinutes(20),
    ]);
});

it('shows watchlisted plate numbers to an owner', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Plates render through PlateNumber::forDisplay, which re-spaces SA plates.
    Livewire::test('pages::overview')
        ->assertSee('Recent Watchlist Hits')
        ->assertSee('HIT 001 GP');
});

it('gives a shop the aggregates without the plates behind them', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop)->create());

    Livewire::test('pages::overview')
        ->assertSee('Total Visits')
        ->assertSee('Mall A')
        ->assertDontSee('HIT 001 GP')
        ->assertDontSee('SHOPPER1');
});

it('hides the security and watchlist cards from shops', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop)->create());

    Livewire::test('pages::overview')
        ->assertDontSee('Security Alerts')
        ->assertDontSee('Recent Watchlist Hits');
});

it('shows the security and watchlist cards to owners', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::overview')
        ->assertSee('Security Alerts')
        ->assertSee('Recent Watchlist Hits');
});

it('sends a platform admin to the cross-tenant view', function () {
    actingAsTenant(User::factory()->platformAdmin()->create());

    Livewire::test('pages::overview')->assertRedirect(route('platform.overview'));
});

it('recalculates when the period changes', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subDays(20),
        'exited_at' => Date::now()->subDays(20)->addHour(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    Livewire::test('pages::overview')
        ->assertSet('rangeKey', '7d')
        ->assertSee('Vehicle traffic · last 7 days')
        ->set('rangeKey', '30d')
        ->assertSee('Vehicle traffic · last 30 days');
});

it('falls back to the default period when the query string is nonsense', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => 'forever'])
        ->test('pages::overview')
        ->assertSet('rangeKey', '7d');
});

it('leaves staff plates out of the headline numbers', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'STAFF001',
        'entered_at' => Date::now()->subHours(3),
        'exited_at' => Date::now()->subHours(1),
        'dwell_minutes' => 120,
        'status' => VisitStatus::Closed,
    ]);

    PlateTag::create([
        'site_id' => $this->site->id,
        'plate_number' => 'STAFF001',
        'tag' => PlateTagType::RecurringPattern,
        'tagged_at' => now(),
    ]);

    Livewire::test('pages::overview')->assertDontSee('STAFF001');
});

it('reshapes the dashboard for today, showing currently-on-site instead of return rate', function () {
    // A visit that's still open right now.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'ONSITE01',
        'entered_at' => Date::now()->subMinutes(30),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => 'today'])
        ->test('pages::overview')
        ->assertSet('rangeKey', 'today')
        // Today mode swaps in a live counter for the return-rate KPI.
        ->assertSee('Currently on site')
        ->assertDontSee('Return Rate')
        // Comparison caption changes to "vs yesterday" instead of "vs previous period".
        ->assertSee('vs yesterday')
        // Chart row collapses to one "today vs yesterday" chart.
        ->assertSee('Today, hour by hour')
        ->assertDontSee('Visits Over Time');
});

it('shows the multi-period chart layout when today is not selected', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::overview')
        ->assertSet('rangeKey', '7d')
        ->assertSee('Return Rate')
        ->assertSee('Visits Over Time')
        ->assertSee('Visits by Time of Day')
        ->assertDontSee('Currently on site');
});

it('narrows the heading and figures to the selected site', function () {
    $second = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);
    $secondCam = Camera::factory()->for($second)->entrance()->create();

    Visit::factory()->for($second)->create([
        'plate_number' => 'MALLB001',
        'entered_at' => Date::now()->subHour(),
        'exited_at' => Date::now(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    // Give Mall B its own watchlisted plate so we can see it show up on the
    // "Recent Watchlist Hits" card once the site is pinned.
    WatchlistPlate::factory()->watch()->for($second)->create([
        'plate_number' => 'MBWATCH1',
    ]);
    PlateEvent::factory()->for($secondCam)->create([
        'plate_number' => 'MBWATCH1',
        'captured_at' => Date::now()->subMinutes(15),
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Without a site pinned, the heading falls back to "Dashboard" and both
    // sites' watchlist hits show up. Plate rendering is re-spaced by
    // PlateNumber::forDisplay when the string matches an SA plate format.
    Livewire::test('pages::overview')
        ->assertSee('Dashboard')
        ->assertSee('HIT 001 GP')
        ->assertSee('MBWATCH1');

    app(Tenancy::class)->setCurrentSiteId($second->id);

    Livewire::test('pages::overview')
        ->assertSee('Mall B')
        ->assertSee('MBWATCH1')
        ->assertDontSee('HIT 001 GP');
});
