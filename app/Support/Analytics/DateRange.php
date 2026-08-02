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
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
        ];
    }

    public static function make(string $key): self
    {
        $now = Date::now();

        return match ($key) {
            'today' => new self('today', 'Today', $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            '30d' => new self('30d', 'Last 30 days', $now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()),
            '90d' => new self('90d', 'Last 90 days', $now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()),
            default => new self('7d', 'Last 7 days', $now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()),
        };
    }

    /**
     * The equally long window immediately before this one, for deltas.
     */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->to);

        return new self(
            $this->key.'_previous',
            'Previous '.$this->label,
            $this->from->copy()->subSeconds($length + 1),
            $this->from->copy()->subSecond(),
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
}
