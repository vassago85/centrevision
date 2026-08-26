<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenancy;
use App\Support\Weather\CurrentWeather;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The service caches per site with a 15-minute TTL. `RefreshDatabase`
    // wipes the database cache table between tests, but a straight `flush`
    // here keeps the intent obvious in each test — nothing about the
    // previous test's HTTP fakes should ever influence this one.
    Cache::flush();

    $this->owner = Organization::factory()->owner()->create();
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

/**
 * Canned Open-Meteo current-conditions response.
 */
function fakeCurrentWeather(float $tempC, int $weatherCode, string $observedAt = '2026-08-26T09:30'): void
{
    Http::fake([
        '*api.open-meteo.com*' => Http::response([
            'current' => [
                'time' => $observedAt,
                'temperature_2m' => $tempC,
                'weather_code' => $weatherCode,
            ],
        ]),
    ]);
}

it('returns null when the tenant has no sites in scope', function () {
    // No sites created; scopeSiteIds() is empty. Nothing to render, no
    // HTTP call should be made either.
    Http::fake([
        '*api.open-meteo.com*' => fn () => throw new RuntimeException('Should not call weather API'),
    ]);

    expect(app(CurrentWeather::class)->forCurrentView())->toBeNull();
});

it('returns the observation as-is for a single site with coordinates', function () {
    Site::factory()->for_($this->owner)->create();

    fakeCurrentWeather(tempC: 18.4, weatherCode: 61);

    $summary = app(CurrentWeather::class)->forCurrentView();

    expect($summary)->not->toBeNull()
        ->and($summary['temp_c'])->toBe(18.4)
        ->and($summary['weather_code'])->toBe(61)
        ->and($summary['weather_label'])->toBe('Rain');
});

it('collapses across multiple sites — worst code wins, temps average', function () {
    // Two sites in scope. Return two different fixtures by sequencing the
    // fake — Open-Meteo is called once per site.
    Site::factory()->for_($this->owner)->create(['name' => 'Site A']);
    Site::factory()->for_($this->owner)->create(['name' => 'Site B']);

    Http::fake([
        '*api.open-meteo.com*' => Http::sequence()
            ->push([
                'current' => ['time' => '2026-08-26T09:30', 'temperature_2m' => 16.0, 'weather_code' => 3],
            ])
            ->push([
                'current' => ['time' => '2026-08-26T09:30', 'temperature_2m' => 20.0, 'weather_code' => 95],
            ]),
    ]);

    $summary = app(CurrentWeather::class)->forCurrentView();

    // Codes: 3 (Overcast) and 95 (Thunderstorm). Worst-by-magnitude is 95.
    expect($summary['weather_code'])->toBe(95)
        ->and($summary['weather_label'])->toBe('Thunderstorm')
        ->and($summary['temp_c'])->toBe(18.0);
});

it('silently skips sites without coordinates', function () {
    Site::factory()->for_($this->owner)->create(['name' => 'With coords']);
    Site::factory()->for_($this->owner)->withoutCoordinates()->create(['name' => 'No coords']);

    fakeCurrentWeather(tempC: 22.0, weatherCode: 0);

    $summary = app(CurrentWeather::class)->forCurrentView();

    // Only the site with coordinates contributed — the no-coords one didn't
    // hit the API at all. If it had, the sequenced fake would fail because
    // the second call would find no response.
    expect($summary['weather_label'])->toBe('Clear')
        ->and($summary['temp_c'])->toBe(22.0);

    Http::assertSentCount(1);
});

it('caches per site so a second call in the TTL window makes no HTTP request', function () {
    Site::factory()->for_($this->owner)->create();

    fakeCurrentWeather(tempC: 15.0, weatherCode: 0);

    $service = app(CurrentWeather::class);

    $first = $service->forCurrentView();
    $second = $service->forCurrentView();

    expect($first)->toEqual($second);
    Http::assertSentCount(1);
});

it('returns a hideable summary when Open-Meteo fails', function () {
    Site::factory()->for_($this->owner)->create();

    // 5xx from Open-Meteo. The client's retry() re-attempts, so the fake
    // must respond on every attempt — a single-shot response would only
    // be consumed by attempt 1 and let attempts 2 & 3 escape the fake.
    Http::fake([
        '*api.open-meteo.com*' => Http::response(null, 503),
    ]);

    $summary = app(CurrentWeather::class)->forCurrentView();

    // The service cached a null-fields sentinel so a poll storm won't
    // hammer upstream. The blade template hides the pill because both
    // fields are null.
    expect($summary)->not->toBeNull()
        ->and($summary['temp_c'])->toBeNull()
        ->and($summary['weather_code'])->toBeNull()
        ->and($summary['weather_label'])->toBeNull();
});

it('honours the site switcher — one site in scope means one site read', function () {
    $siteA = Site::factory()->for_($this->owner)->create(['name' => 'Site A']);
    Site::factory()->for_($this->owner)->create(['name' => 'Site B']);

    app(Tenancy::class)->setCurrentSiteId($siteA->getKey());

    fakeCurrentWeather(tempC: 14.5, weatherCode: 45);

    $summary = app(CurrentWeather::class)->forCurrentView();

    expect($summary['weather_label'])->toBe('Fog')
        ->and($summary['temp_c'])->toBe(14.5);

    Http::assertSentCount(1);
});
