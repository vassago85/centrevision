<?php

use App\Enums\VisitStatus;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteDayStat;
use App\Models\User;
use App\Models\Visit;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);

    foreach ([1, 2, 2, 3] as $daysAgo) {
        Visit::factory()->for($this->site)->create([
            'entered_at' => Date::now()->subDays($daysAgo)->setTime(14, 0),
            'exited_at' => Date::now()->subDays($daysAgo)->setTime(14, 30),
            'dwell_minutes' => 30,
            'status' => VisitStatus::Closed,
        ]);
    }
});

it('summarises the period for an owner', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::reports')
        ->assertSet('rangeKey', '30d')
        ->assertSet('tab', 'summary')
        ->assertSee('Visits')
        ->assertSee('Unique vehicles')
        ->assertSee('Returning share')
        ->assertSee('Typical stay')
        ->assertSee('Median of 4 completed visits')
        ->assertSee('Trend by day')
        ->assertSee('Daily breakdown')
        ->assertSeeHtml('data-test="export-menu"');
});

it('keeps busiest day, peak hour and daily average in one compact highlights panel', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::reports')
        ->assertSee('Busiest day')
        ->assertSee('Peak hour')
        ->assertSee('Daily average')
        ->assertSee('visits by vehicles recognised as staff or regulars are left out');
});

it('is available to shops, since it is only aggregates', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    actingAsTenant(User::factory()->shopAdmin($shop)->create());

    Livewire::test('pages::reports')
        ->assertSee('Mall A')
        ->assertSee('Unique vehicles')
        ->assertDontSee('Scheduled reports');
});

it('counts the busiest day correctly', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $busiest = Date::now()->subDays(2)->format('j M');

    Livewire::test('pages::reports')
        ->assertSee($busiest)
        ->assertSee('2 visits');
});

it('narrows to today when asked, dropping the older visits', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subHour(),
        'exited_at' => Date::now(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    Livewire::test('pages::reports')
        ->set('rangeKey', 'today')
        ->assertSee('All sites · today')
        ->assertSeeInOrder(['Visits', '1']);
});

it('offers the five report tabs to owners', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $component = Livewire::test('pages::reports')
        ->assertSee('Traffic patterns')
        ->assertSee('Visit behaviour')
        ->assertSee('System health');

    expect(array_keys($component->instance()->tabs))
        ->toBe(['summary', 'traffic', 'behaviour', 'security', 'health']);
});

it('hides security and system health from shop accounts', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    actingAsTenant(User::factory()->shopAdmin($shop)->create());

    Livewire::test('pages::reports')
        ->assertSee('Visit behaviour')
        ->assertDontSee('System health')
        ->assertDontSee('Watchlist matches')
        ->set('tab', 'health')
        ->assertSet('tab', 'summary');
});

it('renders each tab with its own content', function (string $tab, string $marker, string $absent) {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['tab' => $tab])
        ->test('pages::reports')
        ->assertSet('tab', $tab)
        ->assertSee($marker)
        ->assertDontSee($absent);
})->with([
    'summary' => ['summary', 'Highlights', 'Repeat visit frequency'],
    'traffic' => ['traffic', 'Busy times', 'Highlights'],
    'behaviour' => ['behaviour', 'Repeat visit frequency', 'Busy times'],
    'security' => ['security', 'Incident history', 'Highlights'],
    'health' => ['health', 'Matching diagnostics', 'Incident history'],
]);

it('opens old ?section= links on the matching new tab', function (string $section, string $tab) {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['section' => $section])
        ->test('pages::reports')
        ->assertSet('tab', $tab);
})->with([
    ['overview', 'summary'],
    ['visits', 'traffic'],
    ['occupancy', 'traffic'],
    ['dwell', 'behaviour'],
    ['behaviour', 'behaviour'],
    ['security', 'security'],
    ['quality', 'health'],
]);

it('does not let a legacy link open an ops tab for a shop', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    actingAsTenant(User::factory()->shopAdmin($shop)->create());

    Livewire::withQueryParams(['section' => 'quality'])
        ->test('pages::reports')
        ->assertSet('tab', 'summary');
});

it('pages the daily breakdown ten days at a time, newest first', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $component = Livewire::test('pages::reports');
    $first = $component->instance()->dailyRows;

    expect($first)->toHaveCount(10)
        ->and($first->first()['date'])->toBe(Date::today()->toDateString())
        ->and($component->instance()->dailyPageCount)->toBe(3);

    $component->call('nextDailyPage')->assertSet('dailyPage', 2);

    expect($component->instance()->dailyRows->first()['date'])
        ->toBe(Date::today()->subDays(10)->toDateString());

    $component->call('previousDailyPage')->call('previousDailyPage')->assertSet('dailyPage', 1);
});

it('shows a compact empty state rather than empty charts when there were no incidents', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['tab' => 'security'])
        ->test('pages::reports')
        ->assertSee('No incidents recorded for this period')
        ->assertDontSeeHtml('name="reports-incidents"');
});

it('explains denominators and renames orphan counts on system health', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::withQueryParams(['tab' => 'health'])
        ->test('pages::reports')
        ->assertSee('Plate reads received')
        ->assertSee('Eligible for matching')
        ->assertSee('Entries without matching exits')
        ->assertSee('Exits without matching entries')
        ->assertSee('How these numbers are calculated')
        ->assertSee('Uptime history over the period is not recorded')
        ->assertDontSee('Orphan')
        ->assertDontSee('Camera uptime');
});

it('flags a typical stay built on few completed visits', function () {
    Visit::query()->delete();
    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subDays(1)->setTime(10, 0),
        'exited_at' => Date::now()->subDays(1)->setTime(10, 2),
        'dwell_minutes' => 2,
        'status' => VisitStatus::Closed,
    ]);

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $stay = Livewire::test('pages::reports')
        ->assertSee('Limited stay data')
        ->instance()->summaryCards[3];

    expect($stay['value'])->toBe('2 min')
        ->and($stay['delta'])->toBeNull()
        ->and($stay['warning'])->toContain('Low sample');
});

it('hides occupancy until the selected site has parking capacity', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    Livewire::test('pages::reports')
        ->set('tab', 'traffic')
        ->assertDontSee('Parking pressure');

    $this->site->update(['settings' => ['parking_capacity' => 200]]);

    Livewire::test('pages::reports')
        ->set('tab', 'traffic')
        ->assertSee('Peak occupancy')
        ->assertSee('Parking pressure');
});

it('puts public-holiday context in the trend tooltips and the on-demand list', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $holiday = Date::now()->subDays(2)->startOfDay();

    SiteDayStat::factory()->for($this->site)->publicHoliday("Women's Day")->create([
        'local_date' => $holiday->toDateString(),
    ]);

    $component = Livewire::withQueryParams(['range' => '7d'])
        ->test('pages::reports')
        ->assertSee("Women's Day");

    $annotations = $component->instance()->dayAnnotations;

    expect($annotations)->not->toBeEmpty()
        ->and(array_values($annotations)[0])->toContain("Public holiday: Women's Day");
});

it('drops public holidays and wet days from the trend chart only when asked', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $plain = Date::now()->subDays(1)->startOfDay();
    $holiday = Date::now()->subDays(2)->startOfDay();

    SiteDayStat::factory()->for($this->site)->create(['local_date' => $plain->toDateString()]);
    SiteDayStat::factory()->for($this->site)->publicHoliday('Freedom Day')->create([
        'local_date' => $holiday->toDateString(),
    ]);

    $component = Livewire::withQueryParams(['range' => '7d'])
        ->test('pages::reports')
        ->assertSet('excludeHolidays', false)
        ->assertDontSee('holidays hidden');

    expect($component->instance()->trend['labels'])->toContain($holiday->format('j M'));

    $component->set('excludeHolidays', true)->assertSee('holidays hidden');

    expect($component->instance()->trend['labels'])
        ->not->toContain($holiday->format('j M'))
        ->toContain($plain->format('j M'));

    // Headline totals still count the holiday's visits.
    expect($component->instance()->summaryCards[0]['value'])->toBe('4');
});
