<?php

namespace App\Support\Weather;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Live "now" weather for the tenant's site(s), for the dashboard header pill.
 *
 * Design constraints, mirroring the daily pipeline:
 *
 *   - Sites without coordinates are silently skipped, never an error.
 *   - Open-Meteo failures return null; the header pill just disappears.
 *   - Cached per site so a wire:poll cycle (or three simultaneous open tabs)
 *     never re-hits the upstream — Open-Meteo refreshes current conditions
 *     ~every 15 minutes anyway, and their free tier is small enough to
 *     respect.
 *   - Collapse across multiple sites in scope matches DayContextAnalytics:
 *     the most severe weather code wins the label, temperatures are
 *     averaged. Not perfect for a national retailer, but consistent with
 *     the daily context the dashboard already renders.
 */
class CurrentWeather
{
    /**
     * How long a per-site observation is trusted. Aligns with Open-Meteo's
     * upstream refresh cadence — asking twice inside this window would just
     * hand us the same data. Applied to both success *and* failure so a real
     * outage doesn't turn every dashboard render into a fresh HTTP attempt.
     */
    private const CACHE_TTL_SECONDS = 900;

    public function __construct(private readonly OpenMeteoClient $client) {}

    /**
     * Collapsed summary for the tenant's site scope. Returns null when
     * there is nothing worth rendering — no accessible sites, none of
     * them have coordinates, or every site's observation came back with
     * null fields (upstream failure). The caller should treat null and
     * all-null-fields identically: hide the pill.
     *
     * @return array{temp_c: float|null, weather_code: int|null, weather_label: string|null}|null
     */
    public function forCurrentView(): ?array
    {
        $sites = $this->sitesInScope();

        if ($sites->isEmpty()) {
            return null;
        }

        $observations = $sites
            ->map(fn (Site $site) => $this->forSite($site))
            ->filter(fn (?array $obs) => $obs !== null)
            ->values();

        if ($observations->isEmpty()) {
            return null;
        }

        $temps = $observations
            ->pluck('temp_c')
            ->filter(fn ($v) => $v !== null);

        $worst = $observations
            ->filter(fn (array $obs) => $obs['weather_code'] !== null)
            ->sortByDesc('weather_code')
            ->first();

        $code = $worst['weather_code'] ?? null;

        return [
            'temp_c' => $temps->count() > 0 ? round((float) $temps->avg(), 1) : null,
            'weather_code' => $code,
            'weather_label' => WmoWeatherCode::label($code),
        ];
    }

    /**
     * Per-site observation, cached. Sites without coordinates return null
     * before the cache is even consulted — a site can gain coordinates at
     * any time and the very next call should pick that up without waiting
     * for a stale "no data" key to expire.
     *
     * @return array{temp_c: float|null, weather_code: int|null}|null
     */
    protected function forSite(Site $site): ?array
    {
        if (! $site->hasCoordinates()) {
            return null;
        }

        $key = "weather.current.site.{$site->getKey()}";

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($site) {
            $observation = $this->client->current(
                (float) $site->latitude,
                (float) $site->longitude,
                $site->resolvedTimezone(),
            );

            // A null observation means Open-Meteo failed. We still cache a
            // slim "no data" sentinel so a poll storm during an outage
            // doesn't hammer upstream — the null fields will simply be
            // filtered out by the collapse step and the pill will hide.
            return [
                'temp_c' => $observation['temp_c'] ?? null,
                'weather_code' => $observation['weather_code'] ?? null,
            ];
        });
    }

    /**
     * The tenant's sites for the current dashboard view. Uses `scopeSiteIds`
     * so the site switcher is honoured: a viewer looking at one site sees
     * that site's weather; a viewer looking at "all sites" sees the
     * collapsed average.
     *
     * @return Collection<int, Site>
     */
    protected function sitesInScope(): Collection
    {
        $scopeIds = app(Tenancy::class)->scopeSiteIds();

        if ($scopeIds === []) {
            return collect();
        }

        return Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $scopeIds)
            ->get();
    }
}
