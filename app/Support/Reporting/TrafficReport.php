<?php

namespace App\Support\Reporting;

use App\Support\Analytics\DateRange;
use App\Support\Analytics\TrafficAnalytics;
use App\Support\Utf8;
use Illuminate\Support\Collection;

/**
 * One traffic report, assembled once and rendered as CSV, PDF or email.
 *
 * The figures come from TrafficAnalytics, so an export shows exactly what the
 * Reports page showed: no second implementation to drift out of step.
 */
class TrafficReport
{
    public readonly string $scope;

    public function __construct(
        protected TrafficAnalytics $analytics,
        public readonly DateRange $range,
        string $scope,
    ) {
        $this->scope = Utf8::clean($scope);
    }

    /**
     * @return array{total: int, daily_average: int, average_dwell: int|null, median_dwell: int|null, repeat_percentage: float|null, peak_hour: string|null}
     */
    public function summary(): array
    {
        $dwell = $this->analytics->dwellSummary($this->range);
        $total = $this->analytics->totalVisits($this->range);
        $peak = $this->analytics->peakHour($this->range);

        return [
            'total' => $total,
            'daily_average' => (int) round($total / max(1, $this->daily()->count())),
            'average_dwell' => $dwell['average'],
            'median_dwell' => $dwell['median'],
            'repeat_percentage' => $this->analytics->repeatVisitorPercentage($this->range),
            'peak_hour' => $peak['label'] ?? null,
        ];
    }

    /**
     * @return Collection<int, array{date: string, label: string, count: int}>
     */
    public function daily(): Collection
    {
        return once(fn () => $this->analytics->visitsByDay($this->range));
    }

    /**
     * @return Collection<int, array{hour: int, label: string, count: int}>
     */
    public function hourly(): Collection
    {
        return once(fn () => $this->analytics->visitsByHour($this->range));
    }

    /**
     * @return Collection<int, array{label: string, count: int, percent: float}>
     */
    public function dwellDistribution(): Collection
    {
        return once(fn () => $this->analytics->dwellDistribution($this->range));
    }

    public function title(): string
    {
        return config('app.name').' report — '.$this->scope.' — '.$this->range->label;
    }

    public function filename(string $extension): string
    {
        return str($this->scope.' '.$this->range->label)
            ->slug()
            ->append('-', now()->format('Ymd'), '.', $extension)
            ->toString();
    }
}
