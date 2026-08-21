<?php

namespace App\Enums;

enum AlertRule: string
{
    case WatchlistHit = 'watchlist_hit';
    case Dwell = 'dwell';
    case OddHour = 'odd_hour';
    case MultiEntry = 'multi_entry';

    public function label(): string
    {
        return match ($this) {
            self::WatchlistHit => 'Watchlist hit',
            self::Dwell => 'Dwell over threshold',
            self::OddHour => 'Odd-hour pattern',
            self::MultiEntry => 'Multi-entry today',
        };
    }
}
