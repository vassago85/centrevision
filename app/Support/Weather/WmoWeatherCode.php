<?php

namespace App\Support\Weather;

/**
 * WMO weather interpretation codes → short human labels.
 *
 * Reference: https://open-meteo.com/en/docs (bottom of the "daily variables"
 * section). Grouped into buckets that a mall owner actually cares about —
 * "was it wet, cold, or fine?" rather than the 27 individual code variants.
 * Storing the label alongside the numeric code means the UI doesn't need
 * this mapping at render time.
 */
class WmoWeatherCode
{
    public static function label(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match (true) {
            $code === 0 => 'Clear',
            $code === 1 => 'Mainly clear',
            $code === 2 => 'Partly cloudy',
            $code === 3 => 'Overcast',
            in_array($code, [45, 48], true) => 'Fog',
            in_array($code, [51, 53, 55, 56, 57], true) => 'Drizzle',
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => 'Rain',
            in_array($code, [71, 73, 75, 77, 85, 86], true) => 'Snow',
            in_array($code, [95, 96, 99], true) => 'Thunderstorm',
            default => 'Unknown',
        };
    }
}
