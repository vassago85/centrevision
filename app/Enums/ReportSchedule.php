<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum ReportSchedule: string
{
    case Off = 'off';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Do not send',
            self::Weekly => 'Every Monday',
            self::Monthly => 'First of the month',
        };
    }

    /**
     * The reporting window key this schedule sends.
     */
    public function rangeKey(): string
    {
        return $this === self::Monthly ? '30d' : '7d';
    }

    /**
     * Whether a report is due on this date. Weekly lands on Monday and monthly
     * on the first, both covering the period that has just closed.
     */
    public function isDueOn(CarbonInterface $date): bool
    {
        return match ($this) {
            self::Off => false,
            self::Weekly => $date->isMonday(),
            self::Monthly => $date->day === 1,
        };
    }
}
