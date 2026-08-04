<?php

use App\Support\Platform\PlatformSettings;

it('pushes stored settings into the config repository on boot', function () {
    app(PlatformSettings::class)->set('services.mailgun.secret', 'sk_live_from_db');
    app(PlatformSettings::class)->set('trafficflow.security_operator_monthly_amount', '25.00');

    // Re-boot the provider so it re-reads and overrides. In a real request
    // this happens once at framework boot; a unit test has to prompt it.
    $provider = new App\Providers\PlatformSettingsServiceProvider($this->app);
    $provider->boot();

    expect(config('services.mailgun.secret'))->toBe('sk_live_from_db')
        // Cast to float per the SETTINGS map so downstream math works.
        ->and(config('trafficflow.security_operator_monthly_amount'))->toBe(25.0);
});

it('leaves config alone for keys the admin has not touched', function () {
    // Nothing stored: whatever is in .env / config/*.php should stand.
    config()->set('services.mailgun.secret', 'from-env');

    $provider = new App\Providers\PlatformSettingsServiceProvider($this->app);
    $provider->boot();

    expect(config('services.mailgun.secret'))->toBe('from-env');
});

it('does not raise if the settings table has not been migrated yet', function () {
    // A fresh install runs artisan commands before migrations complete. If
    // the provider throws here, `artisan migrate` itself would fail before
    // it could create the table.
    \Illuminate\Support\Facades\Schema::drop('platform_settings');

    $provider = new App\Providers\PlatformSettingsServiceProvider($this->app);

    expect(fn () => $provider->boot())->not->toThrow(Throwable::class);
});
