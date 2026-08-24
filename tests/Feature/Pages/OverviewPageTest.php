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
use App\Models\SiteDayStat;
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
        // Dashboard defaults to Today, so the headline visit KPI reads
        // "Visits Today". Commercial copy uses "Unique Visitors" now.
        ->assertSee('Visits Today')
        ->assertSee('Unique Visitors')
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

it('counts a watchlist hit as a new alert when the user has never visited security', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // The HIT001GP watchlist plate + event exists from beforeEach. With
    // alerts_last_seen_at null, the 24h fallback window applies and the
    // event (20 minutes old) counts.
    $component = Livewire::test('pages::overview');

    $counts = $component->instance()->alertCounts;

    expect($counts['watchlist'])->toBe(1)
        ->and($counts['total'])->toBeGreaterThan(0);
});

it('clears the notification count after the user visits security', function () {
    $user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Visit /security — this stamps alerts_last_seen_at on the user.
    Livewire::test('pages::security');

    // The watchlist event from beforeEach is now older than seen_at, so it
    // should no longer count as new.
    $counts = Livewire::test('pages::overview')->instance()->alertCounts;

    expect($counts['watchlist'])->toBe(0)
        ->and($counts['blacklist'])->toBe(0)
        ->and($counts['total'])->toBe(0);
});

it('bumps the count again when a new event arrives after acknowledgement', function () {
    $user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Acknowledge existing alerts.
    Livewire::test('pages::security');

    // Advance the clock so the new event is unambiguously after the
    // acknowledgement timestamp — same-second collisions would otherwise
    // hide the alert we're trying to prove is visible.
    $this->travel(1)->minutes();

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'HIT001GP',
        'captured_at' => Date::now(),
    ]);

    $counts = Livewire::test('pages::overview')->instance()->alertCounts;

    expect($counts['watchlist'])->toBe(1);
});

it('sends a platform admin to the cross-tenant view', function () {
    actingAsTenant(User::factory()->platformAdmin()->create());

    Livewire::test('pages::overview')->assertRedirect(route('platform.overview'));
});

it('recalculates when the period changes', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::overview')
        // Dashboard defaults to Today — it is the "what is happening now"
        // screen, and longer windows live under Reports.
        ->assertSet('rangeKey', 'today')
        ->assertSee('today')
        ->set('rangeKey', '7d')
        ->assertSee('last 7 days');
});

it('falls back to the default period when the query string is nonsense', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => 'forever'])
        ->test('pages::overview')
        ->assertSet('rangeKey', 'today');
});

it('drops the 30-day and 90-day ranges from the dashboard picker', function () {
    // Longer windows are Reports' job. If a bookmark points at them, the
    // dashboard should silently reset to today rather than opening a range
    // that no longer belongs to this screen.
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => '30d'])
        ->test('pages::overview')
        ->assertSet('rangeKey', 'today');

    Livewire::withQueryParams(['range' => '90d'])
        ->test('pages::overview')
        ->assertSet('rangeKey', 'today');
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

it('drops the on-site-now and dwell KPIs when the site has no exit-capable camera', function () {
    // Site has only the entrance camera from beforeEach; no Exit or Both.
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => 'today'])
        ->test('pages::overview')
        // Entry-only sites cannot report a truthful on-site count or dwell.
        ->assertDontSee('On Site Now')
        ->assertDontSee('Average Dwell')
        // Replaced with figures that entries-only data can actually compute.
        ->assertSee('Peak hour')
        ->assertSee('Repeat visitors')
        // Honest disclosure of why dwell is missing.
        ->assertSee('add an exit camera for dwell');
});

it('leads with On Site Now on both Today and 7 days when the site has an exit camera', function () {
    Camera::factory()->for($this->site)->exit()->create(['name' => 'South exit']);

    // A visit that's still open right now — On Site Now should count it
    // regardless of which range the user is looking at.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'ONSITE01',
        'entered_at' => Date::now()->subMinutes(30),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    foreach (['today', '7d'] as $range) {
        Livewire::withQueryParams(['range' => $range])
            ->test('pages::overview')
            // On Site Now is a live count; it does not depend on the range picker.
            ->assertSee('On Site Now')
            // Commercial rename: Unique Vehicles → Unique Visitors.
            ->assertSee('Unique Visitors')
            // Return Rate is always the visitor-based rate now, not the
            // visit-weighted flavour that used to hide behind the same label.
            ->assertSee('Return Rate')
            ->assertSee('Average Dwell')
            ->assertDontSee('add an exit camera for dwell');
    }
});

it('uses the visitor-based Return Rate formula on the dashboard', function () {
    Camera::factory()->for($this->site)->exit()->create();

    // Two shoppers in the 7-day window; one of them also has a prior visit
    // before the window opens. The visitor-based Return Rate is therefore
    // exactly 50% (1 returning / 2 unique). The visit-weighted flavour on
    // the same data would have reported 0% because neither plate has more
    // than one visit *inside* the window.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'RETURN01',
        'entered_at' => Date::now()->subDays(30),
        'exited_at' => Date::now()->subDays(30)->addHour(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'RETURN01',
        'entered_at' => Date::now()->subDays(2),
        'exited_at' => Date::now()->subDays(2)->addHour(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'FIRST001',
        'entered_at' => Date::now()->subDays(1),
        'exited_at' => Date::now()->subDays(1)->addHour(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => '7d'])
        ->test('pages::overview')
        ->assertSee('Return Rate')
        ->assertSee('50%');
});

it('shows the multi-day chart layout on 7 days', function () {
    Camera::factory()->for($this->site)->exit()->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => '7d'])
        ->test('pages::overview')
        ->assertSet('rangeKey', '7d')
        ->assertSee('Visits Over Time')
        ->assertSee('Visits by Time of Day')
        ->assertDontSee('Today, hour by hour');
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

it('polls on every range so the dashboard stays live without manual refresh', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Cadence is tuned to how fast the underlying numbers can plausibly
    // change — Today's counters move minute by minute, a 7-day view moves
    // more slowly but still needs to be live because On Site Now is on
    // every dashboard.
    $expected = [
        'today' => 'wire:poll.15s',
        '7d' => 'wire:poll.30s',
    ];

    foreach ($expected as $range => $directive) {
        Livewire::withQueryParams(['range' => $range])
            ->test('pages::overview')
            ->assertSet('rangeKey', $range)
            ->assertSeeHtml($directive);
    }
});

it('picks up a fresh plate event on the next poll without a remount', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $component = Livewire::test('pages::overview');

    // Sanity-check the initial "Latest activity" table doesn't already list
    // the plate we're about to insert — otherwise the assertion below would
    // be vacuous. Plate uses SA format so PlateNumber::forDisplay re-spaces
    // it the same way the existing seed data ("HIT 001 GP") is re-spaced.
    $component->assertDontSeeHtml('NEW 999 GP');

    // Simulate a camera reporting a plate in between two poll cycles.
    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'NEW999GP',
        'captured_at' => Date::now(),
    ]);

    // A wire:poll cycle is functionally a $refresh on the same component
    // instance — this exercises the same code path without simulating
    // Livewire's timer.
    $component->call('$refresh')
        ->assertSeeHtml('NEW 999 GP');
});

it('surfaces public-holiday context in the visits-over-time chart annotations', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Pin a day inside the default 7d window to a known holiday, then
    // seed a matching site_day_stats row — the enrichment job would
    // normally do this, but we short-circuit it for the test.
    $holiday = Date::now()->subDays(2)->startOfDay();

    SiteDayStat::factory()->for($this->site)->publicHoliday("Women's Day")->create([
        'local_date' => $holiday->toDateString(),
    ]);

    $component = Livewire::test('pages::overview')->assertSet('rangeKey', '7d');

    // The chip strip is the visible surface for the marker; the tooltip
    // annotation is baked into the chart payload and is what actually
    // renders on hover in the browser.
    $component->assertSee("Women's Day");

    $annotations = $component->instance()->dayAnnotations;
    expect($annotations)->not->toBeEmpty();
    expect(array_values($annotations)[0])->toContain("Public holiday: Women's Day");
});

it('drops public-holiday days from the daily chart when the toggle is on', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Two days inside the default 7d window: one plain, one holiday.
    // Seed matching day stats so the toggle actually has something to
    // filter against.
    $plain = Date::now()->subDays(1)->startOfDay();
    $holiday = Date::now()->subDays(2)->startOfDay();

    SiteDayStat::factory()->for($this->site)->create([
        'local_date' => $plain->toDateString(),
    ]);
    SiteDayStat::factory()->for($this->site)->publicHoliday('Freedom Day')->create([
        'local_date' => $holiday->toDateString(),
    ]);

    $component = Livewire::test('pages::overview')->assertSet('excludeHolidays', false);

    $labelsBefore = $component->instance()->visitsOverTime['labels'];
    expect($labelsBefore)->toContain($holiday->format('j M'));

    $component->set('excludeHolidays', true);

    $labelsAfter = $component->instance()->visitsOverTime['labels'];
    expect($labelsAfter)
        ->not->toContain($holiday->format('j M'))
        ->and($labelsAfter)->toContain($plain->format('j M'));
});

it('keeps the excludeHolidays toggle off by default so existing views are unchanged', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::overview')
        ->assertSet('excludeHolidays', false)
        // Copy that only appears when the filter is on — makes sure the
        // header caption isn't lying about the default state.
        ->assertDontSee('holidays hidden');
});
