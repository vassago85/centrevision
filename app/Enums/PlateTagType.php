<?php

namespace App\Enums;

/**
 * System-inferred classifications applied to plates. User-managed watchlists
 * live in the WatchlistPlate model, not here.
 */
enum PlateTagType: string
{
    case RecurringPattern = 'recurring_pattern';

    public function label(): string
    {
        return match ($this) {
            self::RecurringPattern => 'Recurring pattern',
        };
    }

    /**
     * Recurring-pattern plates are almost certainly staff or tenants, so they
     * are excluded from every shopper-facing metric.
     */
    public function excludedFromShopperMetrics(): bool
    {
        return $this === self::RecurringPattern;
    }
}
