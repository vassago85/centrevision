<?php

use App\Enums\PlateTagType;
use App\Enums\VisitStatus;
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
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // Midday, so "two hours ago" is always still today.
    $this->travelTo(Date::today()->setTime(12, 0));

    // Sites ship with Pretoria coordinates, so the header weather pill
    // would otherwise call Open-Meteo on every render. Windows/Laragon
    // often lacks a CA bundle and the suite 500s on cURL error 60.
    Http::fake([
        '*api.open-meteo.com*' => Http::response([
            'current' => [
                'time' => '2026-09-11T09:00',
                'temperature_2m' => 18.0,
                'weather_code' => 1,
            ],
        ]),
    ]);

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
        ->assertSee('Recent watchlist matches')
        ->assertSee('HIT 001 GP');
});

it('gives a shop the aggregates without the plates behind them', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop)->create());

    Livewire::test('pages::overview')
        ->assertSee('Visits today')
        ->assertSee('Unique vehicles')
        ->assertSee('Mall A')
        ->assertDontSee('HIT 001 GP')
        ->assertDontSee('SHOPPER1');
});

it('hides the security panel and recent activity from shops', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop)->create());

    Livewire::test('pages::overview')
        ->assertDontSee('Security & watchlist')
        ->assertDontSee('Recent watchlist matches')
        ->assertDontSee('Recent activity')
        // Shops cannot open the Cameras page, so it is not linked for them.
        ->assertDontSeeHtml('href="'.route('cameras').'"');
});

it('shows the security panel and recent activity to owners', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::overview')
        ->assertSee('Security & watchlist')
        ->assertSee('Recent watchlist matches')
        ->assertSee('Recent activity')
        ->assertSeeHtml('href="'.route('activity').'"');
});

it('hides disk usage from owners — infrastructure numbers are platform-only', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::overview')
        ->assertDontSee('Plate photos');
});

it('hides disk usage from shops', function () {
    actingAsTenant(User::factory()->shopAdmin($this->shop)->create());

    Livewire::test('pages::overview')
        ->assertDontSee('Plate photos');
});

it('counts a watchlist hit as a new alert when the user has never visited security', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $component = Livewire::test('pages::overview')
        ->assertSee('New in the last 24 hours');

    $counts = $component->instance()->alertCounts;

    expect($counts['watchlist'])->toBe(1)
        ->and($counts['total'])->toBeGreaterThan(0);
});

it('clears the notification count after the user visits security', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    // Visit /security — this stamps alerts_last_seen_at on the user.
    Livewire::test('pages::security');

    $component = Livewire::test('pages::overview')
        ->assertSee('New since you last opened Security');

    $counts = $component->instance()->alertCounts;

    expect($counts['watchlist'])->toBe(0)
        ->and($counts['blacklist'])->toBe(0)
        ->and($counts['total'])->toBe(0);
});

it('bumps the count again when a new event arrives after acknowledgement', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::security');

    // Same-second collisions would otherwise hide the alert.
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
        ->assertSet('rangeKey', 'today')
        ->assertSee('at a glance · today')
        ->set('rangeKey', '7d')
        ->assertSee('at a glance · last 7 days');
});

it('falls back to the default period when the query string is nonsense', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => 'forever'])
        ->test('pages::overview')
        ->assertSet('rangeKey', 'today');
});

it('drops the 30-day and 90-day ranges from the dashboard picker', function () {
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

it('explains the missing on-site and stay figures when the site has no exit camera', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $component = Livewire::test('pages::overview')
        ->assertSee('Vehicles on site')
        ->assertSee('Typical stay')
        ->assertSee('Needs an exit camera')
        ->assertDontSee('Median of');

    // Unavailable, not zero.
    expect($component->instance()->cards[0]['value'])->toBe('—')
        ->and($component->instance()->cards[3]['value'])->toBe('—');
});

it('keeps the on-site count live on both Today and 7 days when the site has an exit camera', function () {
    Camera::factory()->for($this->site)->exit()->create(['name' => 'South exit']);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'ONSITE01',
        'entered_at' => Date::now()->subMinutes(30),
        'exited_at' => null,
        'dwell_minutes' => null,
        'status' => VisitStatus::Open,
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    foreach (['today', '7d'] as $range) {
        $component = Livewire::withQueryParams(['range' => $range])
            ->test('pages::overview')
            ->assertSee('Vehicles on site')
            ->assertSee('Unique vehicles')
            ->assertSee('Typical stay')
            ->assertSee('Median of 1 completed visit')
            ->assertDontSee('Needs an exit camera');

        expect($component->instance()->cards[0]['value'])->toBe('1');
    }
});

it('compares today with yesterday up to the same time, not all of yesterday', function () {
    $this->travelTo(Date::today()->setTime(11, 0));
    Visit::query()->delete();

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'TODAY001',
        'entered_at' => Date::today()->setTime(10, 0),
        'status' => VisitStatus::Open,
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'EARLY001',
        'entered_at' => Date::yesterday()->setTime(9, 0),
        'status' => VisitStatus::Orphaned,
    ]);
    // Later than 11:00 yesterday, so it is outside the matched comparison.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'LATER001',
        'entered_at' => Date::yesterday()->setTime(15, 0),
        'status' => VisitStatus::Orphaned,
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $visits = Livewire::test('pages::overview')->instance()->cards[1];

    expect($visits['value'])->toBe('1')
        ->and($visits['delta'])->toBe('▲ 0.0%')
        ->and($visits['comparison'])->toContain('vs yesterday to 11:00');
});

it('stops the hourly chart at the current hour instead of drawing future zeroes', function () {
    $this->travelTo(Date::today()->setTime(10, 30));

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $hourly = Livewire::test('pages::overview')
        ->assertSee('through 10:30')
        ->instance()->hourly;

    expect($hourly['labels'])->toHaveCount(11)
        ->and(end($hourly['labels']))->toBe('10:00')
        ->and($hourly['previous'])->toHaveCount(11);
});

it('shows the hour-of-day chart across the period on 7 days', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['range' => '7d'])
        ->test('pages::overview')
        ->assertSee('Arrivals by hour of day')
        ->assertDontSee('Today and yesterday');
});

it('labels a stay figure built on too few completed visits and drops its comparison', function () {
    Camera::factory()->for($this->site)->exit()->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $stay = Livewire::test('pages::overview')
        ->assertSee('Low sample')
        ->instance()->cards[3];

    expect($stay['value'])->toBe('60 min')
        ->and($stay['delta'])->toBeNull();
});

it('warns that the on-site count is unreliable when most recent visits missed their exit', function () {
    Camera::factory()->for($this->site)->exit()->create();

    foreach (range(1, 3) as $i) {
        Visit::factory()->for($this->site)->create([
            'plate_number' => "MISSED0{$i}",
            'entered_at' => Date::now()->subDays($i)->setTime(9, 0),
            'status' => VisitStatus::Orphaned,
        ]);
    }

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $onSite = Livewire::test('pages::overview')->instance()->cards[0];

    // 1 matched (SHOPPER1) of 4 finished visits = 25 %, under the 50 % rule.
    expect($onSite['warning'])->toContain('25% of visits in the last 7 days had a matched exit');
});

it('limits recent activity to the five latest detections', function () {
    foreach (range(1, 7) as $i) {
        PlateEvent::factory()->for($this->camera)->create([
            'plate_number' => "RECENT{$i}",
            'captured_at' => Date::now()->subMinutes($i),
        ]);
    }

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    expect(Livewire::test('pages::overview')->instance()->latestEntries)->toHaveCount(5);
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

    WatchlistPlate::factory()->watch()->for($second)->create([
        'plate_number' => 'MBWATCH1',
    ]);
    PlateEvent::factory()->for($secondCam)->create([
        'plate_number' => 'MBWATCH1',
        'captured_at' => Date::now()->subMinutes(15),
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

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

    $expected = [
        'today' => 'wire:poll.15s',
        '7d' => 'wire:poll.30s',
    ];

    foreach ($expected as $range => $directive) {
        Livewire::withQueryParams(['range' => $range])
            ->test('pages::overview')
            ->assertSet('rangeKey', $range)
            ->assertSeeHtml($directive)
            ->assertSeeHtml('data-test="live-status"');
    }
});

it('picks up a fresh plate event on the next poll without a remount', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $component = Livewire::test('pages::overview');

    $component->assertDontSeeHtml('NEW 999 GP');

    PlateEvent::factory()->for($this->camera)->create([
        'plate_number' => 'NEW999GP',
        'captured_at' => Date::now(),
    ]);

    $component->call('$refresh')
        ->assertSeeHtml('NEW 999 GP');
});
