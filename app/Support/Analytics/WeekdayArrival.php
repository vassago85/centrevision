<?php

namespace App\Support\Analytics;

/**
 * One weekday arrival of one plate, as recurring-pattern detection sees it.
 */
class WeekdayArrival
{
    public function __construct(
        public readonly string $plateNumber,
        public readonly string $day,
        public readonly int $minuteOfDay,
    ) {}
}
