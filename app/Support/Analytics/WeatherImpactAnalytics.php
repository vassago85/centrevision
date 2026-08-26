<?php

namespace App\Support\Analytics;

use App\Models\Scopes\SiteScope;
use App\Models\SiteDayStat;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Did the rain actually hurt me?" — the one number this asks and answers.
 *
 * Reads the plate-free `site_day_stats` rollup for the tenant's site scope
 * and range, buckets each day as wet or dry using {@see DayContextAnalytics::WET_LABELS},
 * and reports a median day-of-week-adjusted delta:
 *
 *   For each wet day, compare its visits to the average of *dry days on the
 *   same weekday within the range*. Take the median across wet days.
 *
 * Why DOW-adjusted?
 *   The naive comparison (wet-day-mean vs dry-day-mean) silently confounds
 *   day of week with weather — a wet Sunday against a dry Tuesday-Thursday
 *   is meaningless. Owners will spot that within one glance and lose trust
 *   in every other number this dashboard reports.
 *
 * Why median (not mean)?
 *   A single Black Friday landing on a rainy day should not swing the
 *   period's reported weather impact by 40 points. Median is robust.
 *
 * Why fall back to overall-dry-mean when a weekday has no dry days?
 *   In heavy rain stretches a Saturday might be wet three weeks running
 *   with no dry Saturday in the range. Dropping those wet days would
 *   under-report the effect; using the overall dry baseline instead
 *   under-adjusts for DOW but keeps the sample honest.
 *
 * Why is `has_enough_data` set at ≥3 of each?
 *   Fewer than that and one atypical day distorts the average. The card
 *   should show the same "not enough data yet" line the empty-state does,
 *   rather than a spuriously precise −47% built on two data points.
 */
class WeatherImpactAnalytics
{
    private const MIN_SAMPLE_PER_BUCKET = 3;

    /**
     * Returns null when there's no weather data at all — the caller should
     * hide the card entirely. Returns a hydrated shape (with
     * `has_enough_data` = false) when there is *some* data but not enough
     * to publish a percentage, so the card can render an honest empty
     * state that tells the owner to widen the range.
     *
     * @return array{
     *   has_enough_data: bool,
     *   wet_days_count: int,
     *   dry_days_count: int,
     *   wet_avg_visits: int|null,
     *   dry_avg_visits: int|null,
     *   delta_percent: float|null,
     * }|null
     */
    public function forRange(DateRange $range): ?array
    {
        $siteIds = app(Tenancy::class)->scopeSiteIds();

        if ($siteIds === []) {
            return null;
        }

        $days = $this->collapsedDays($range, $siteIds);

        if ($days->isEmpty()) {
            return null;
        }

        [$wet, $dry] = $days->partition(
            fn (array $day) => in_array($day['label'], DayContextAnalytics::WET_LABELS, true),
        );

        $wetCount = $wet->count();
        $dryCount = $dry->count();

        if ($wetCount < self::MIN_SAMPLE_PER_BUCKET || $dryCount < self::MIN_SAMPLE_PER_BUCKET) {
            return [
                'has_enough_data' => false,
                'wet_days_count' => $wetCount,
                'dry_days_count' => $dryCount,
                'wet_avg_visits' => $wetCount > 0 ? (int) round($wet->avg('visits')) : null,
                'dry_avg_visits' => $dryCount > 0 ? (int) round($dry->avg('visits')) : null,
                'delta_percent' => null,
            ];
        }

        return [
            'has_enough_data' => true,
            'wet_days_count' => $wetCount,
            'dry_days_count' => $dryCount,
            'wet_avg_visits' => (int) round($wet->avg('visits')),
            'dry_avg_visits' => (int) round($dry->avg('visits')),
            'delta_percent' => $this->medianDowAdjustedDeltaPercent($wet, $dry),
        ];
    }

    /**
     * One row per calendar date in range: summed visits across the tenant's
     * sites, worst weather code wins the label (matches the collapse rule
     * used everywhere else on the dashboard). Rows without weather_label —
     * a site with no coordinates that day — are dropped, not classified as
     * "dry", because we genuinely don't know.
     *
     * @return Collection<int, array{date: string, visits: int, label: string, code: int, dow: int}>
     */
    protected function collapsedDays(DateRange $range, array $siteIds): Collection
    {
        $rows = SiteDayStat::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $siteIds)
            ->whereBetween('local_date', [
                CarbonImmutable::parse($range->from)->toDateString(),
                CarbonImmutable::parse($range->to)->toDateString(),
            ])
            ->whereNotNull('weather_label')
            ->toBase()
            ->selectRaw('local_date, visits_count, weather_label, weather_code')
            ->get();

        return $rows
            ->groupBy(fn ($row) => (string) $row->local_date)
            ->map(function (Collection $forDay) {
                $worst = $forDay->sortByDesc('weather_code')->first();
                $date = (string) $worst->local_date;

                return [
                    'date' => $date,
                    'visits' => (int) $forDay->sum('visits_count'),
                    'label' => (string) $worst->weather_label,
                    'code' => (int) $worst->weather_code,
                    'dow' => CarbonImmutable::parse($date)->dayOfWeek,
                ];
            })
            ->values();
    }

    /**
     * Median of per-wet-day deltas, expressed as a percentage. Negative =
     * wet days averaged fewer visits than the dry baseline for that
     * weekday. Returns null only if every wet day pairs with a zero
     * expected baseline (impossible in practice given the guard, but kept
     * defensive so a corner-case null doesn't crash the card).
     *
     * @param  Collection<int, array{date: string, visits: int, dow: int}>  $wet
     * @param  Collection<int, array{date: string, visits: int, dow: int}>  $dry
     */
    protected function medianDowAdjustedDeltaPercent(Collection $wet, Collection $dry): ?float
    {
        $dryByDow = $dry->groupBy('dow');
        $dryOverallMean = (float) $dry->avg('visits');

        $deltas = $wet
            ->map(function (array $wetDay) use ($dryByDow, $dryOverallMean): ?float {
                $sameDow = $dryByDow->get($wetDay['dow']);
                $expected = $sameDow !== null && $sameDow->isNotEmpty()
                    ? (float) $sameDow->avg('visits')
                    : $dryOverallMean;

                if ($expected <= 0) {
                    return null;
                }

                return ($wetDay['visits'] - $expected) / $expected;
            })
            ->filter(fn (?float $delta) => $delta !== null)
            ->sort()
            ->values();

        if ($deltas->isEmpty()) {
            return null;
        }

        $count = $deltas->count();

        $median = $count % 2 === 1
            ? $deltas[intdiv($count, 2)]
            : ($deltas[intdiv($count, 2) - 1] + $deltas[intdiv($count, 2)]) / 2;

        return round($median * 100, 1);
    }
}
