<?php

namespace App\Enums;

/**
 * A plate can appear on the watchlist for three very different reasons.
 *
 * The kinds are deliberately non-overlapping — the same plate can't be both a
 * block and a VIP — so the UI can treat them like separate lists.
 */
enum WatchlistKind: string
{
    /** Vehicle that must not be here at all. */
    case Block = 'block';

    /** Vehicle to be notified about, without escalation. */
    case Watch = 'watch';

    /** High-value regular who should get personal attention. */
    case Vip = 'vip';

    public function label(): string
    {
        return match ($this) {
            self::Block => 'Blocked',
            self::Watch => 'Watch',
            self::Vip => 'VIP',
        };
    }

    /**
     * UI tone for the alerting colour band. Blocks are the loudest; VIPs are
     * positive, watches are just informational.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Block => 'danger',
            self::Watch => 'warn',
            self::Vip => 'positive',
        };
    }
}
