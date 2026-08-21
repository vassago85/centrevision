<?php

namespace App\Support\Alerts;

use App\Enums\AlertRule;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

final class AlertQuietHours
{
    /**
     * When the email may leave. Null means send immediately.
     */
    public static function sendAfter(
        Site $site,
        AlertSettings $settings,
        AlertRule $rule,
        CarbonInterface $detectedAt,
    ): ?CarbonInterface {
        if (! $settings->hasQuietHours() || ! $settings->respectQuiet($rule)) {
            return null;
        }

        $tz = $site->timezone ?: config('app.timezone');
        $local = $detectedAt->copy()->timezone($tz);

        if (! self::inQuietWindow($local, $settings->quietStart(), $settings->quietEnd())) {
            return null;
        }

        return self::nextQuietEnd($local, $settings->quietEnd())->timezone(config('app.timezone'));
    }

    protected static function inQuietWindow(CarbonInterface $local, string $start, string $end): bool
    {
        $minutes = $local->hour * 60 + $local->minute;
        $startMinutes = self::hmToMinutes($start);
        $endMinutes = self::hmToMinutes($end);

        if ($startMinutes === $endMinutes) {
            return false;
        }

        // Overnight window (e.g. 22:00–06:00).
        if ($startMinutes > $endMinutes) {
            return $minutes >= $startMinutes || $minutes < $endMinutes;
        }

        return $minutes >= $startMinutes && $minutes < $endMinutes;
    }

    protected static function nextQuietEnd(CarbonInterface $local, string $end): CarbonInterface
    {
        $endMinutes = self::hmToMinutes($end);
        $candidate = $local->copy()->startOfDay()->addMinutes($endMinutes);

        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate->addDay();
        }

        return Date::parse($candidate);
    }

    protected static function hmToMinutes(string $hm): int
    {
        [$h, $m] = array_pad(explode(':', $hm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }
}
