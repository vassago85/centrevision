<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteDayStat;
use App\Models\User;
use App\Support\Analytics\DateRange;
use App\Support\Analytics\WeatherImpactAnalytics;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    $this->service = app(WeatherImpactAnalytics::class);
});

/**
 * Stamp a single-site day stat row. Dates are ISO strings so the setup
 * reads like a calendar and the DOW-adjustment logic is easy to eyeball.
 */
function dayStat(Site $site, string $date, int $visits, string $label = 'Clear', int $code = 0): SiteDayStat
{
    return SiteDayStat::factory()->for($site)->create([
        'local_date' => $date,
        'visits_count' => $visits,
        'weather_code' => $code,
        'weather_label' => $label,
    ]);
}

it('returns null when the tenant has no weather rows in range', function () {
    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result)->toBeNull();
});

it('returns null when the tenant has no accessible sites', function () {
    // A brand-new tenant with no sites at all. scopeSiteIds() returns
    // empty and the service should short-circuit to null before it even
    // asks the database.
    $lonelyOwner = Organization::factory()->owner()->create();
    actingAsTenant(User::factory()->ownerAdmin($lonelyOwner)->create());

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result)->toBeNull();
});

it('publishes a percentage when both buckets have at least three days', function () {
    // 5 dry weekdays at 200 visits/day, 3 wet days spread across DOWs at
    // 150 visits/day. Wet Mon has a same-DOW baseline (dry Mon = 200);
    // wet Sat and wet Sun fall back to overall dry mean = 200. All three
    // wet-day deltas are (150 - 200) / 200 = -25%. Median = -25%.
    dayStat($this->site, '2026-08-10', 200);              // Mon dry
    dayStat($this->site, '2026-08-11', 200);              // Tue dry
    dayStat($this->site, '2026-08-12', 200);              // Wed dry
    dayStat($this->site, '2026-08-13', 200);              // Thu dry
    dayStat($this->site, '2026-08-14', 200);              // Fri dry
    dayStat($this->site, '2026-08-15', 150, 'Rain', 61);  // Sat wet
    dayStat($this->site, '2026-08-16', 150, 'Rain', 61);  // Sun wet
    dayStat($this->site, '2026-08-17', 150, 'Rain', 61);  // Mon wet

    $result = $this->service->forRange(DateRange::custom('2026-08-10', '2026-08-17'));

    expect($result['has_enough_data'])->toBeTrue()
        ->and($result['wet_days_count'])->toBe(3)
        ->and($result['dry_days_count'])->toBe(5)
        ->and($result['wet_avg_visits'])->toBe(150)
        ->and($result['dry_avg_visits'])->toBe(200)
        ->and($result['delta_percent'])->toBe(-25.0);
});

it('adjusts for day-of-week when the naive mean would be misleading', function () {
    // Setup: Sundays are naturally busier than weekdays. Three wet Sundays
    // at 300 visits, three dry Sundays at 400, and three dry weekdays at
    // 100. The naive wet-vs-dry mean would report +20% (wet 300 vs dry
    // (400+400+400+100+100+100)/6 = 250) — as if the rain HELPED.
    //
    // The DOW-adjusted delta uses "wet Sun" vs "dry Sun" only:
    // (300 - 400) / 400 = -25%. That's the honest number.
    foreach (['2026-08-02', '2026-08-09', '2026-08-16'] as $dryStub) {
        dayStat($this->site, $dryStub, 400); // Sundays, dry
    }
    foreach (['2026-08-23', '2026-08-30', '2026-09-06'] as $wetSun) {
        dayStat($this->site, $wetSun, 300, 'Rain', 61); // Sundays, wet
    }
    foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $dryWeekday) {
        dayStat($this->site, $dryWeekday, 100); // Mon-Wed, dry
    }

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-09-30'));

    expect($result['has_enough_data'])->toBeTrue()
        ->and($result['delta_percent'])->toBe(-25.0);
});

it('falls back to the overall dry mean when a weekday has no dry sample', function () {
    // Three wet Tuesdays with no dry Tuesday in range. The DOW-adjusted
    // calc has no same-weekday baseline for any of them, so all three
    // fall back to the overall dry mean, which comes purely from dry
    // Mondays. Overall dry mean = 200. Delta per wet Tue = (100-200)/200
    // = -50%. Median = -50%.
    foreach (['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24'] as $dryMon) {
        dayStat($this->site, $dryMon, 200); // Mondays, dry
    }
    foreach (['2026-08-04', '2026-08-11', '2026-08-18'] as $wetTue) {
        dayStat($this->site, $wetTue, 100, 'Rain', 61); // Tuesdays, wet
    }

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result['has_enough_data'])->toBeTrue()
        ->and($result['delta_percent'])->toBe(-50.0);
});

it('reports has_enough_data=false when the sample is thin', function () {
    // Two wet days and five dry days — the wet bucket is below the
    // credibility threshold, so the card should show its "not enough
    // data yet" state instead of publishing a spurious percentage.
    for ($i = 0; $i < 5; $i++) {
        dayStat($this->site, '2026-08-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 200);
    }
    dayStat($this->site, '2026-08-06', 150, 'Rain', 61);
    dayStat($this->site, '2026-08-07', 150, 'Rain', 61);

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result)->not->toBeNull()
        ->and($result['has_enough_data'])->toBeFalse()
        ->and($result['wet_days_count'])->toBe(2)
        ->and($result['dry_days_count'])->toBe(5)
        // Averages are still populated so the card can show the raw
        // numbers even when it withholds the delta percentage.
        ->and($result['wet_avg_visits'])->toBe(150)
        ->and($result['dry_avg_visits'])->toBe(200)
        ->and($result['delta_percent'])->toBeNull();
});

it('reports has_enough_data=false when zero wet days occurred', function () {
    // Nothing but dry days in the range. The card shouldn't publish a
    // delta ("no wet days to compare to") but should also not vanish —
    // the tenant clearly has weather data, so the honest empty state is
    // the right thing to render.
    for ($i = 0; $i < 10; $i++) {
        dayStat($this->site, '2026-08-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 200);
    }

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result)->not->toBeNull()
        ->and($result['has_enough_data'])->toBeFalse()
        ->and($result['wet_days_count'])->toBe(0)
        ->and($result['dry_days_count'])->toBe(10);
});

it('skips days with no weather label — we cannot classify them either way', function () {
    // A site without coordinates produces day-stat rows with null
    // weather_label. Those rows must not be silently reclassified as
    // "dry" (there's a real difference between "we know it was clear"
    // and "we don't know"). The service drops them, so this test's
    // sample sizes should ignore the four unknown rows.
    for ($i = 0; $i < 4; $i++) {
        dayStat($this->site, '2026-08-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 999, 'Clear', 0);
    }
    // Now override those four rows to null-label by mutating the DB
    // directly — matches what the enrichment job writes for a site with
    // no coordinates.
    SiteDayStat::query()->update(['weather_label' => null, 'weather_code' => null]);

    // Add four dry + three wet with real labels.
    dayStat($this->site, '2026-08-10', 200);
    dayStat($this->site, '2026-08-11', 200);
    dayStat($this->site, '2026-08-12', 200);
    dayStat($this->site, '2026-08-13', 200);
    dayStat($this->site, '2026-08-14', 150, 'Rain', 61);
    dayStat($this->site, '2026-08-15', 150, 'Rain', 61);
    dayStat($this->site, '2026-08-16', 150, 'Rain', 61);

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result['wet_days_count'])->toBe(3)
        ->and($result['dry_days_count'])->toBe(4);
});

it('classifies Rain, Thunderstorm and Snow as wet — Overcast and Drizzle are not', function () {
    // Matches DayContextAnalytics::WET_LABELS, which is deliberately
    // narrow: the chip strip flags these three, and the impact card
    // should count exactly the same days so the two views agree.
    dayStat($this->site, '2026-08-10', 200);
    dayStat($this->site, '2026-08-11', 200);
    dayStat($this->site, '2026-08-12', 200);
    dayStat($this->site, '2026-08-13', 200, 'Overcast', 3);   // dry-ish
    dayStat($this->site, '2026-08-14', 200, 'Drizzle', 51);   // dry-ish
    dayStat($this->site, '2026-08-15', 100, 'Rain', 61);
    dayStat($this->site, '2026-08-16', 100, 'Thunderstorm', 95);
    dayStat($this->site, '2026-08-17', 100, 'Snow', 71);

    $result = $this->service->forRange(DateRange::custom('2026-08-01', '2026-08-31'));

    expect($result['wet_days_count'])->toBe(3)
        // 3 Clear + 1 Overcast + 1 Drizzle = 5 dry.
        ->and($result['dry_days_count'])->toBe(5);
});
