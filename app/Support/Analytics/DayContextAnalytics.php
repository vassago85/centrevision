<?php

namespace App\Support\Analytics;

use App\Models\Scopes\SiteScope;
use App\Models\SiteDayStat;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Weather + holiday context for the tenant's site(s), on a per-day grain.
 *
 * Lives next to TrafficAnalytics rather than inside it so the "headline
 * traffic numbers" API stays untouched. Everything here reads from the
 * plate-free `site_day_stats` rollup, so it survives POPIA pruning.
 *
 * When the tenant has multiple sites in scope, the day-level flags are
 * OR-combined ("was any site on holiday that day?") and weather is a
 * simple average. Neither is a perfect story for a national retailer with
 * spread-out sites, but for the ZA-first, mall-per-site v1 it matches
 * what the dashboard already assumes: one owner, one bag of numbers.
 */
class DayContextAnalytics
{
    /**
     * Per-day context rows for the given range, keyed by ISO date. Rows
     * exist only for days we've enriched — a call for "last 30 days" on a
     * freshly-installed system may return fewer than 30 entries.
     *
     * @return Collection<string, array{
     *   date: string,
     *   is_public_holiday: bool,
     *   is_school_holiday: bool,
     *   holiday_name: string|null,
     *   weather_label: string|null,
     *   weather_code: int|null,
     *   precip_mm: float|null,
     *   temp_avg_c: float|null,
     * }>
     */
    public function forRange(DateRange $range): Collection
    {
        $siteIds = app(Tenancy::class)->scopeSiteIds();

        if ($siteIds === []) {
            return collect();
        }

        $rows = SiteDayStat::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $siteIds)
            ->whereBetween('local_date', [
                CarbonImmutable::parse($range->from)->toDateString(),
                CarbonImmutable::parse($range->to)->toDateString(),
            ])
            ->toBase()
            ->get();

        // Bucket by date and collapse across sites. A day is a "public
        // holiday" on the chart if it was one for *any* site in scope
        // (owners with sites across provinces are the exception, not
        // the rule, and school-term drift between provinces is at most
        // a week). Weather is averaged so a two-site tenant doesn't get
        // dueling temperatures on the same bar.
        return $rows
            ->groupBy(fn ($row) => (string) $row->local_date)
            ->map(function (Collection $forDay, string $iso) {
                $temps = $forDay->pluck('temp_avg_c')->filter(fn ($v) => $v !== null);
                $precip = $forDay->pluck('precip_mm')->filter(fn ($v) => $v !== null);
                $withHolidayName = $forDay->firstWhere('holiday_name', '!=', null);

                // Weather label is the most severe one reported that day
                // — Rain > Thunderstorm > Overcast etc. Ranking is by
                // WMO code magnitude which happens to correlate well
                // enough with "shopper impact" for a v1 marker.
                $worstWx = $forDay
                    ->filter(fn ($row) => $row->weather_code !== null)
                    ->sortByDesc('weather_code')
                    ->first();

                return [
                    'date' => $iso,
                    'is_public_holiday' => (bool) $forDay->contains(fn ($row) => (bool) $row->is_public_holiday),
                    'is_school_holiday' => (bool) $forDay->contains(fn ($row) => (bool) $row->is_school_holiday),
                    'holiday_name' => $withHolidayName?->holiday_name,
                    'weather_label' => $worstWx?->weather_label,
                    'weather_code' => $worstWx?->weather_code === null ? null : (int) $worstWx->weather_code,
                    'precip_mm' => $precip->count() > 0 ? round($precip->avg(), 1) : null,
                    'temp_avg_c' => $temps->count() > 0 ? round($temps->avg(), 1) : null,
                ];
            });
    }

    /**
     * Local dates within the range that are flagged as public holidays.
     * Used by the "Exclude holidays" toggle to filter the daily series.
     *
     * @return array<int, string>
     */
    public function publicHolidayDates(DateRange $range): array
    {
        return $this->forRange($range)
            ->filter(fn (array $ctx) => $ctx['is_public_holiday'])
            ->keys()
            ->all();
    }
}
