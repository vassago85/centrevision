<?php

use App\Enums\PlateTagType;
use App\Jobs\EnrichSiteDayStats;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\SiteDayStat;
use App\Models\Visit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Deterministic "now" so date-boundary logic in the job doesn't drift
    // between test runs. Set well after ZA's summer break so no calendar
    // rows collide with our assertions.
    $this->travelTo(Date::parse('2026-08-10 06:00:00', 'Africa/Johannesburg'));

    $this->site = Site::factory()->create([
        'latitude' => -25.7479,
        'longitude' => 28.2293,
        'timezone' => 'Africa/Johannesburg',
        'country_code' => 'ZA',
    ]);
});

/**
 * Canned Open-Meteo daily response covering the requested date range.
 *
 * @param  array<int, string>  $dates
 * @param  array<int, float>  $temps
 * @param  array<int, float>  $precip
 * @param  array<int, int>  $codes
 */
function fakeOpenMeteo(array $dates, array $temps, array $precip, array $codes): void
{
    Http::fake([
        '*api.open-meteo.com*' => Http::response([
            'daily' => [
                'time' => $dates,
                'temperature_2m_mean' => $temps,
                'precipitation_sum' => $precip,
                'weather_code' => $codes,
            ],
        ]),
    ]);
}

it('upserts a plate-free row per local calendar day', function () {
    // Two visits on 2026-08-09 (yesterday, Jo'burg), one on 2026-08-08.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'AAA111GP',
        'entered_at' => Date::parse('2026-08-09 10:00:00', 'Africa/Johannesburg')->utc(),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'BBB222GP',
        'entered_at' => Date::parse('2026-08-09 14:00:00', 'Africa/Johannesburg')->utc(),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'CCC333GP',
        'entered_at' => Date::parse('2026-08-08 09:00:00', 'Africa/Johannesburg')->utc(),
    ]);

    fakeOpenMeteo(
        dates: ['2026-08-08', '2026-08-09'],
        temps: [12.5, 18.0],
        precip: [0.0, 5.2],
        codes: [0, 61],
    );

    EnrichSiteDayStats::dispatchSync(siteId: $this->site->id, backfillDays: 2);

    // Row schema is plate-free by design — no plate column exists at all.
    $rows = SiteDayStat::query()->orderBy('local_date')->get();
    expect($rows)->toHaveCount(2);

    // local_date carries a date cast, so firstWhere against a bare string
    // won't match a Carbon; the callback form keeps the comparison honest.
    $day1 = $rows->first(fn ($row) => $row->local_date->toDateString() === '2026-08-08');
    $day2 = $rows->first(fn ($row) => $row->local_date->toDateString() === '2026-08-09');

    expect((int) $day1->visits_count)->toBe(1)
        ->and((int) $day1->unique_vehicles)->toBe(1)
        ->and($day1->weather_label)->toBe('Clear');

    expect((int) $day2->visits_count)->toBe(2)
        ->and((int) $day2->unique_vehicles)->toBe(2)
        ->and($day2->weather_label)->toBe('Rain');
});

it('excludes recurring (staff) plates from the day counts', function () {
    // Same rule TrafficAnalytics uses — the rollup and the live dashboard
    // must agree, otherwise chart/KPI numbers would disagree.
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'SHOPPER1',
        'entered_at' => Date::parse('2026-08-09 10:00:00', 'Africa/Johannesburg')->utc(),
    ]);
    Visit::factory()->for($this->site)->create([
        'plate_number' => 'STAFF001',
        'entered_at' => Date::parse('2026-08-09 10:30:00', 'Africa/Johannesburg')->utc(),
    ]);

    PlateTag::create([
        'site_id' => $this->site->id,
        'plate_number' => 'STAFF001',
        'tag' => PlateTagType::RecurringPattern,
        'tagged_at' => now(),
    ]);

    fakeOpenMeteo(['2026-08-09'], [15.0], [0.0], [0]);

    EnrichSiteDayStats::dispatchSync(siteId: $this->site->id, backfillDays: 1);

    $row = SiteDayStat::query()->where('local_date', '2026-08-09')->first();
    expect((int) $row->visits_count)->toBe(1);
});

it('flags South African public holidays without calling the weather API', function () {
    // Pin to a real ZA holiday (Freedom Day 2026-04-27 is a Monday).
    $this->travelTo(Date::parse('2026-04-28 06:00:00', 'Africa/Johannesburg'));

    fakeOpenMeteo(['2026-04-27'], [22.0], [0.0], [0]);

    EnrichSiteDayStats::dispatchSync(siteId: $this->site->id, backfillDays: 1);

    $row = SiteDayStat::query()->where('local_date', '2026-04-27')->first();
    expect($row->is_public_holiday)->toBeTrue()
        ->and($row->holiday_name)->toBe('Freedom Day');
});

it('skips the weather lookup when the site has no coordinates', function () {
    $this->site->update(['latitude' => null, 'longitude' => null]);

    // A failing Http::fake would fail the test if the job called out.
    Http::fake([
        '*api.open-meteo.com*' => fn () => throw new RuntimeException('Should not call weather API'),
    ]);

    Visit::factory()->for($this->site)->create([
        'plate_number' => 'XYZ999GP',
        'entered_at' => Date::parse('2026-08-09 10:00:00', 'Africa/Johannesburg')->utc(),
    ]);

    EnrichSiteDayStats::dispatchSync(siteId: $this->site->id, backfillDays: 1);

    $row = SiteDayStat::query()->where('local_date', '2026-08-09')->first();
    expect((int) $row->visits_count)->toBe(1)
        ->and($row->weather_label)->toBeNull()
        ->and($row->temp_avg_c)->toBeNull();
});

it('is idempotent — a re-run refreshes rather than duplicates rows', function () {
    fakeOpenMeteo(['2026-08-09'], [15.0], [0.0], [0]);

    EnrichSiteDayStats::dispatchSync(siteId: $this->site->id, backfillDays: 1);
    EnrichSiteDayStats::dispatchSync(siteId: $this->site->id, backfillDays: 1);

    expect(SiteDayStat::query()->count())->toBe(1);
});
