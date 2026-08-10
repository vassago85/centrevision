<?php

namespace App\Jobs;

use App\Enums\PlateTagType;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteDayStat;
use App\Models\Visit;
use App\Support\Calendar\SouthAfrica\PublicHolidays;
use App\Support\Calendar\SouthAfrica\SchoolTerms;
use App\Support\Weather\OpenMeteoClient;
use App\Support\Weather\WmoWeatherCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Nightly enrichment: for every site, upsert one row per local calendar day
 * into `site_day_stats` with visit aggregates + weather + holiday flags.
 *
 * Runs after {@see MatchVisits} has settled yesterday's data. Idempotent —
 * a re-run just refreshes the same rows via upsert, so backfills, missed
 * nights and partial failures are all safe.
 *
 * Design constraints:
 *   - Never touches plate numbers. The rollup is POPIA-safe by construction.
 *   - Sites without coordinates get holiday flags + visit counts but no
 *     weather columns (nulls), rather than an error.
 *   - One Open-Meteo call per site per run, covering the whole backfill
 *     window — batching keeps us well under the free-tier rate limits.
 */
class EnrichSiteDayStats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    /**
     * On a first run for a fresh install, how many days of history to
     * seed. Capped so a long-running deployment doesn't hammer the
     * weather API when a new site is added — subsequent runs only ever
     * touch yesterday + today.
     */
    protected const BACKFILL_DAYS = 30;

    public function __construct(
        public ?int $siteId = null,
        public int $backfillDays = self::BACKFILL_DAYS,
    ) {}

    public function uniqueId(): string
    {
        return (string) ($this->siteId ?? 'all');
    }

    public function handle(OpenMeteoClient $weather): void
    {
        foreach ($this->sites() as $site) {
            $this->enrichSite($site, $weather);
        }
    }

    /**
     * @return iterable<Site>
     */
    protected function sites(): iterable
    {
        return Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->when($this->siteId !== null, fn ($query) => $query->whereKey($this->siteId))
            ->cursor();
    }

    protected function enrichSite(Site $site, OpenMeteoClient $weather): void
    {
        $timezone = $site->resolvedTimezone();

        // Local "yesterday" is the newest day we ever want to rewrite —
        // MatchVisits has had all night to settle it. "Today" is deliberately
        // left alone until it fully unrolls; the live dashboard reads from
        // `visits` directly for today's counters anyway.
        $end = CarbonImmutable::now($timezone)->subDay()->startOfDay();
        $start = $end->copy()->subDays($this->backfillDays - 1);

        // Only fetch weather for sites that have coordinates. Sites without
        // still get visit counts + holiday flags (holidays are national and
        // don't depend on lat/lng) so the rollup is useful either way.
        $weatherByDate = $site->hasCoordinates()
            ? $weather->daily(
                (float) $site->latitude,
                (float) $site->longitude,
                $start,
                $end,
                $timezone,
            )
            : [];

        $visitCounts = $this->visitCounts($site, $start, $end, $timezone);

        $upserts = [];

        for ($cursor = $start; $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addDay()) {
            $iso = $cursor->toDateString();
            $day = $visitCounts[$iso] ?? ['visits_count' => 0, 'unique_vehicles' => 0];
            $wx = $weatherByDate[$iso] ?? null;

            $holidayName = $site->resolvedCountryCode() === 'ZA'
                ? PublicHolidays::nameFor($cursor)
                : null;

            $isSchoolHoliday = $site->resolvedCountryCode() === 'ZA'
                && SchoolTerms::isSchoolHoliday($cursor);

            $upserts[] = [
                'site_id' => $site->getKey(),
                'local_date' => $iso,
                'visits_count' => $day['visits_count'],
                'unique_vehicles' => $day['unique_vehicles'],
                'temp_avg_c' => $wx['temp_avg_c'] ?? null,
                'precip_mm' => $wx['precip_mm'] ?? null,
                'weather_code' => $wx['weather_code'] ?? null,
                'weather_label' => WmoWeatherCode::label($wx['weather_code'] ?? null),
                'is_public_holiday' => $holidayName !== null,
                'is_school_holiday' => $isSchoolHoliday,
                'holiday_name' => $holidayName,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        if ($upserts === []) {
            return;
        }

        SiteDayStat::query()->upsert(
            $upserts,
            ['site_id', 'local_date'],
            [
                'visits_count', 'unique_vehicles',
                'temp_avg_c', 'precip_mm', 'weather_code', 'weather_label',
                'is_public_holiday', 'is_school_holiday', 'holiday_name',
                'updated_at',
            ],
        );

        Log::info('Enriched site day stats', [
            'site_id' => $site->getKey(),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'weather_days' => count($weatherByDate),
        ]);
    }

    /**
     * Aggregate visit counts per local calendar day, respecting the site's
     * timezone and the same "excluding recurring plates" rule that the
     * dashboard's headline numbers use.
     *
     * Returns a map keyed by ISO date string so the enrichment loop is a
     * straight `$counts[$iso] ?? 0` lookup.
     *
     * @return array<string, array{visits_count: int, unique_vehicles: int}>
     */
    protected function visitCounts(Site $site, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        // Query in UTC bounds that comfortably span the requested local
        // window (add a day either side for timezones far from UTC) and
        // then bucket in PHP by the timezone-shifted local date. This lets
        // Postgres use the (site_id, entered_at) index without needing a
        // functional index on a timezone conversion.
        $rows = Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->excludingRecurring()
            ->whereBetween('entered_at', [
                $start->copy()->subDay()->utc(),
                $end->copy()->addDay()->endOfDay()->utc(),
            ])
            ->toBase()
            ->selectRaw('entered_at, plate_number')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $localDate = CarbonImmutable::parse($row->entered_at)
                ->setTimezone($timezone)
                ->toDateString();

            if ($localDate < $start->toDateString() || $localDate > $end->toDateString()) {
                continue;
            }

            $buckets[$localDate] ??= ['visits_count' => 0, 'unique_plates' => []];
            $buckets[$localDate]['visits_count']++;
            $buckets[$localDate]['unique_plates'][$row->plate_number] = true;
        }

        return collect($buckets)
            ->map(fn (array $bucket) => [
                'visits_count' => $bucket['visits_count'],
                'unique_vehicles' => count($bucket['unique_plates']),
            ])
            ->all();
    }
}
