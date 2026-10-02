<?php

namespace App\Support\Analytics;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * A named reporting window. Pages hand one of these to TrafficAnalytics rather
 * than passing loose dates around, so the "vs previous period" comparison
 * always lines up with the window the user actually picked.
 */
class DateRange
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
    ) {}

    /**
     * Live dashboard windows.
     *
     * The Dashboard is deliberately narrow: it answers "what is happening now
     * and today?". Longer historical windows (30d, 90d, this/last month, …)
     * belong to Reports via {@see reportOptions()}, so the two surfaces do
     * not feel like duplicates.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'today' => 'Today',
            '7d' => 'Last 7 days',
        ];
    }

    /**
     * Historical reporting windows. Includes the dashboard keys plus the
     * extra periods the Reports page needs. `custom` is a sentinel — callers
     * must build that window with custom().
     *
     * @return array<string, string>
     */
    public static function reportOptions(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'custom' => 'Custom',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function comparisonOptions(): array
    {
        return [
            'previous' => 'Previous period',
            'month' => 'Previous month',
            'year' => 'Previous year',
            'none' => 'None',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function audienceOptions(): array
    {
        return [
            'shopper' => 'Shopper / visitor',
            'staff' => 'Staff / regular',
            'all' => 'All',
        ];
    }

    public static function make(string $key): self
    {
        $now = Date::now();

        return match ($key) {
            'today' => new self('today', 'Today', $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'yesterday' => new self(
                'yesterday',
                'Yesterday',
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ),
            '30d' => new self('30d', 'Last 30 days', $now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()),
            '90d' => new self('90d', 'Last 90 days', $now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()),
            'this_month' => new self(
                'this_month',
                'This month',
                $now->copy()->startOfMonth(),
                $now->copy()->endOfDay(),
            ),
            'last_month' => new self(
                'last_month',
                'Last month',
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ),
            default => new self('7d', 'Last 7 days', $now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()),
        };
    }

    public static function custom(string|CarbonInterface $from, string|CarbonInterface $to): self
    {
        $start = Date::parse($from)->startOfDay();
        $end = Date::parse($to)->endOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return new self('custom', 'Custom', $start, $end);
    }

    /**
     * The equally long window immediately before this one, for deltas.
     */
    public function previous(): self
    {
        $end = $this->from->copy()->subMicrosecond();

        return new self(
            $this->key.'_previous',
            'Previous '.$this->label,
            $end->copy()->sub($this->from->diff($this->to)),
            $end,
        );
    }

    public function days(): int
    {
        return max(1, (int) ceil($this->from->diffInSeconds($this->to) / 86400));
    }

    public function spansMultipleDays(): bool
    {
        return $this->days() > 1;
    }

    /**
     * Chart grain for historical series. Today/yesterday stay hourly;
     * multi-week ranges collapse to weeks so a 90-day chart stays readable.
     *
     * @return 'hour'|'day'|'week'
     */
    public function grain(): string
    {
        if (! $this->spansMultipleDays()) {
            return 'hour';
        }

        return $this->days() > 60 ? 'week' : 'day';
    }

    /**
     * The window to overlay when the Reports page asks for a comparison.
     * `previous` keeps the existing equal-length preceding window.
     */
    public function comparisonRange(string $mode): ?self
    {
        return match ($mode) {
            'previous' => $this->previous(),
            'month' => $this->shifted('month'),
            'year' => $this->shifted('year'),
            default => null,
        };
    }

    /**
     * The comparison window cut to the same elapsed length as this one. While
     * this window is still running (Today at 11:04, Last 7 days ending
     * tonight) the comparison stops at the matching moment instead of
     * counting its whole length, so a partial day is never set against a
     * full one. Finished windows get the plain comparisonRange().
     */
    public function elapsedComparisonRange(string $mode, ?CarbonInterface $now = null): ?self
    {
        $comparison = $this->comparisonRange($mode);
        $now ??= Date::now();

        if ($comparison === null || ! $this->isInProgress($now)) {
            return $comparison;
        }

        $cut = $comparison->from->copy()->addSeconds((int) $this->from->diffInSeconds($now));

        return new self(
            $comparison->key,
            $comparison->label,
            $comparison->from,
            $cut->lt($comparison->to) ? $cut : $comparison->to,
        );
    }

    public function isInProgress(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now();

        return $now->gt($this->from) && $now->lt($this->to);
    }

    public function shifted(string $unit): self
    {
        $method = $unit === 'year' ? 'subYearNoOverflow' : 'subMonthNoOverflow';

        return new self(
            $this->key.'_'.$unit,
            $unit === 'year' ? 'Previous year' : 'Previous month',
            $this->from->copy()->{$method}(),
            $this->to->copy()->{$method}(),
        );
    }
}
