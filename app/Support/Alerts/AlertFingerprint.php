<?php

namespace App\Support\Alerts;

use App\Enums\AlertRule;
use App\Models\Site;
use Carbon\CarbonInterface;

final class AlertFingerprint
{
    /**
     * @param  array{visit_id?: int|null, window_start?: string|null, detected_at?: CarbonInterface|null}  $context
     */
    public static function make(Site $site, AlertRule $rule, string $plate, array $context = []): string
    {
        $plate = strtoupper($plate);
        $siteId = $site->getKey();
        $settings = AlertSettings::for($site);

        return match ($rule) {
            AlertRule::WatchlistHit => sprintf(
                '%d|watchlist|%s|%d',
                $siteId,
                $plate,
                self::bucket($context['detected_at'] ?? now(), $settings->dedupeMinutes()),
            ),
            AlertRule::Dwell => sprintf(
                '%d|dwell|%d',
                $siteId,
                (int) ($context['visit_id'] ?? 0),
            ),
            AlertRule::MultiEntry => sprintf(
                '%d|multi|%s|%s',
                $siteId,
                $plate,
                ($context['detected_at'] ?? now())->timezone($site->timezone ?: config('app.timezone'))->toDateString(),
            ),
            AlertRule::OddHour => sprintf(
                '%d|odd|%s|%s',
                $siteId,
                $plate,
                $context['window_start'] ?? now()->subDays((int) config('trafficflow.security.odd_hour_window_days', 14))->toDateString(),
            ),
        };
    }

    protected static function bucket(CarbonInterface $at, int $dedupeMinutes): int
    {
        return (int) floor($at->getTimestamp() / max(60, $dedupeMinutes * 60));
    }
}
