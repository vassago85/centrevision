<?php

namespace App\Support\Analytics;

use App\Enums\VisitStatus;
use App\Models\Site;
use App\Models\Visit;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

/**
 * Historical occupancy derived from visit entry/exit state.
 *
 * Live occupancy still lives on TrafficAnalytics. This class reconstructs
 * "how full was the car park" across a reporting window, and only when the
 * selected site has a configured parking capacity.
 */
class OccupancyAnalytics
{
    public function available(?Site $site = null): bool
    {
        $site ??= app(Tenancy::class)->currentSite();
        $site?->refresh();

        return $site?->parkingCapacity() !== null;
    }

    /**
     * @return array{
     *   capacity: int,
     *   peak: int,
     *   peak_at: string|null,
     *   average: float,
     *   minutes_above_80: int,
     *   minutes_above_90: int,
     *   parking_pressure: string
     * }|null
     */
    public function summary(DateRange $range): ?array
    {
        $site = app(Tenancy::class)->currentSite();
        $site?->refresh();
        $capacity = $site?->parkingCapacity();

        if ($capacity === null) {
            return null;
        }

        $series = $this->series($range);
        $peak = $series->sortByDesc('count')->first();
        $step = $this->stepMinutes($range);

        $above80 = $series->filter(fn (array $point) => $point['count'] / $capacity >= 0.8)->count() * $step;
        $above90 = $series->filter(fn (array $point) => $point['count'] / $capacity >= 0.9)->count() * $step;

        return [
            'capacity' => $capacity,
            'peak' => (int) ($peak['count'] ?? 0),
            'peak_at' => ($peak['count'] ?? 0) > 0 ? ($peak['date'] ?? null) : null,
            'average' => $series->isEmpty() ? 0.0 : round($series->avg('count'), 1),
            'minutes_above_80' => $above80,
            'minutes_above_90' => $above90,
            'parking_pressure' => self::formatDuration($above80),
        ];
    }

    /**
     * Occupancy sampled across the window. Hourly for short ranges, daily
     * once the window is longer than two months.
     *
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    public function series(DateRange $range): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        $visits = $this->relevantVisits($range);
        $step = $this->stepMinutes($range);
        $points = collect();

        for ($cursor = $range->from->copy(); $cursor->lte($range->to); $cursor = $cursor->addMinutes($step)) {
            $onSite = $visits->filter(function (Visit $visit) use ($cursor): bool {
                if ($visit->entered_at->gt($cursor)) {
                    return false;
                }

                return $visit->exited_at === null || $visit->exited_at->gt($cursor);
            })->count();

            $points->push([
                'date' => $cursor->toDateTimeString(),
                'label' => $step >= 1440 ? $cursor->format('j M') : $cursor->format($range->spansMultipleDays() ? 'j M H:i' : 'H:i'),
                'count' => $onSite,
            ]);
        }

        return $points;
    }

    public static function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            return $mins.'m';
        }

        if ($mins === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$mins.'m';
    }

    protected function stepMinutes(DateRange $range): int
    {
        return $range->grain() === 'week' ? 1440 : 60;
    }

    /**
     * Closed and still-open visits that overlapped the window. Orphans are
     * left out — occupancy is a valid entry/exit concept.
     *
     * @return Collection<int, Visit>
     */
    protected function relevantVisits(DateRange $range): Collection
    {
        return Visit::query()
            ->excludingRecurring()
            ->whereIn('status', [VisitStatus::Open, VisitStatus::Closed])
            ->where('entered_at', '<=', $range->to)
            ->where(function ($query) use ($range): void {
                $query->whereNull('exited_at')
                    ->orWhere('exited_at', '>=', $range->from);
            })
            ->get(['entered_at', 'exited_at', 'status']);
    }
}
