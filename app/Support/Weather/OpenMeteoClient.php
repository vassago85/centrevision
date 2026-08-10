<?php

namespace App\Support\Weather;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Open-Meteo's forecast + archive APIs.
 *
 * We use the free, key-less endpoint. Anything older than ~5 days lives on
 * the archive host; anything more recent lives on the forecast host. The
 * client picks the right one based on the requested date range.
 *
 * Kept in one class so the enrichment job stays testable — a Http::fake()
 * in a Pest test is all that's needed to replay a canned response.
 */
class OpenMeteoClient
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    /**
     * Daily weather summary for one coordinate over a date range.
     *
     * @return array<string, array{temp_avg_c: float|null, precip_mm: float|null, weather_code: int|null}>
     *   Keyed by ISO date string. Missing days simply won't be in the map;
     *   the caller should treat that as "no data for that day", not error.
     */
    public function daily(
        float $latitude,
        float $longitude,
        CarbonInterface $from,
        CarbonInterface $to,
        string $timezone = 'Africa/Johannesburg',
    ): array {
        $endpoint = $this->pickEndpoint($from, $to);

        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(2, 250)
            ->get($endpoint, [
                'latitude' => round($latitude, 4),
                'longitude' => round($longitude, 4),
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                // Open-Meteo returns *daily* aggregates in the timezone we
                // ask for. Passing the site timezone lines its "day" up with
                // ours, so `temperature_2m_mean` really is that local day's
                // mean and not a UTC bucket that spills midnight.
                'timezone' => $timezone,
                'daily' => 'temperature_2m_mean,precipitation_sum,weather_code',
            ]);

        if (! $response->successful()) {
            Log::warning('Open-Meteo lookup failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
                'lat' => $latitude,
                'lng' => $longitude,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);

            return [];
        }

        $daily = $response->json('daily') ?? [];
        $dates = $daily['time'] ?? [];
        $temps = $daily['temperature_2m_mean'] ?? [];
        $precip = $daily['precipitation_sum'] ?? [];
        $codes = $daily['weather_code'] ?? [];

        $out = [];

        foreach ($dates as $index => $iso) {
            $out[(string) $iso] = [
                'temp_avg_c' => isset($temps[$index]) ? (float) $temps[$index] : null,
                'precip_mm' => isset($precip[$index]) ? (float) $precip[$index] : null,
                'weather_code' => isset($codes[$index]) ? (int) $codes[$index] : null,
            ];
        }

        return $out;
    }

    /**
     * Open-Meteo splits data across two hosts: the archive is authoritative
     * for anything more than ~5 days in the past, and the forecast host has
     * the recent + upcoming window. We pick by the range's END date so a
     * "yesterday only" enrichment always hits forecast (which is what we
     * want — archive lags by a few days).
     */
    private function pickEndpoint(CarbonInterface $from, CarbonInterface $to): string
    {
        $cutoff = now()->subDays(5)->startOfDay();

        return $to->lessThan($cutoff) ? self::ARCHIVE_URL : self::FORECAST_URL;
    }
}
