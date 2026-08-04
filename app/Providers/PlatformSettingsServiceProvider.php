<?php

namespace App\Providers;

use App\Support\Platform\PlatformSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wires platform-editable settings into Laravel's config repository so the
 * rest of the app can keep reading `config('services.mailgun.secret')` and
 * transparently get whatever the platform admin last saved in the UI.
 *
 * The alternative — every service reading `PlatformSettings::get()` directly
 * — would force us to touch every mailer, gateway and worker; the whole
 * point of this provider is that most of the code does not need to change.
 */
class PlatformSettingsServiceProvider extends ServiceProvider
{
    /**
     * Non-secret defaults for a fresh install. If a key is missing from the
     * DB and its `env()` fallback is also blank, the framework value stands
     * — this map just documents the shape of the settings the UI writes.
     *
     * The `db` key on each entry is the row in `platform_settings`; the
     * `config` key is the Laravel config path it overrides on boot. Only
     * entries with a config target are pushed into the framework repo on
     * boot; the rest are read on demand via PlatformSettings::get().
     *
     * @var array<string, array{config?: string, cast?: string}>
     */
    public const SETTINGS = [
        // ── Mailgun (transactional email) ──────────────────────────────
        'mail.mailer' => ['config' => 'mail.default'],
        'mail.from.address' => ['config' => 'mail.from.address'],
        'mail.from.name' => ['config' => 'mail.from.name'],
        'services.mailgun.domain' => ['config' => 'services.mailgun.domain'],
        'services.mailgun.secret' => ['config' => 'services.mailgun.secret'],
        'services.mailgun.endpoint' => ['config' => 'services.mailgun.endpoint'],

        // ── Paystack (payments) ────────────────────────────────────────
        'services.paystack.public' => ['config' => 'services.paystack.public'],
        'services.paystack.secret' => ['config' => 'services.paystack.secret'],
        'services.paystack.webhook_secret' => ['config' => 'services.paystack.webhook_secret'],
        'services.paystack.base_url' => ['config' => 'services.paystack.base_url'],

        // ── Global billing knobs ───────────────────────────────────────
        'trafficflow.variable_rate_per_camera_per_subuser' => [
            'config' => 'trafficflow.variable_rate_per_camera_per_subuser',
            'cast' => 'float',
        ],
        'trafficflow.platform_shop_revenue_share' => [
            'config' => 'trafficflow.platform_shop_revenue_share',
            'cast' => 'float',
        ],
        'trafficflow.security_operator_monthly_amount' => [
            'config' => 'trafficflow.security_operator_monthly_amount',
            'cast' => 'float',
        ],
        'trafficflow.partner_commission_rate' => [
            'config' => 'trafficflow.partner_commission_rate',
            'cast' => 'float',
        ],
        'trafficflow.retention_days' => [
            'config' => 'trafficflow.retention_days',
            'cast' => 'int',
        ],

        // ── Landing / support ──────────────────────────────────────────
        'trafficflow.billing_email' => ['config' => 'trafficflow.billing_email'],
        'trafficflow.support_email' => ['config' => 'trafficflow.support_email'],

        // ── Feature flags ──────────────────────────────────────────────
        'trafficflow.demo_mode' => [
            'config' => 'trafficflow.demo_mode',
            'cast' => 'bool',
        ],
        'trafficflow.fuzzy_match_enabled' => [
            'config' => 'trafficflow.fuzzy_match_enabled',
            'cast' => 'bool',
        ],
        'trafficflow.dwell_alert_hours' => [
            'config' => 'trafficflow.dwell_alert_hours',
            'cast' => 'int',
        ],
    ];

    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);
    }

    public function boot(): void
    {
        // Skip during package discovery / migrations before the table exists.
        // A boot-time query that hits a missing table would break `artisan`
        // itself, so we swallow the specific "no table" case and keep going.
        try {
            $stored = app(PlatformSettings::class)->all();
        } catch (Throwable) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app['config'];

        foreach (self::SETTINGS as $key => $meta) {
            if (! isset($stored[$key], $meta['config'])) {
                continue;
            }

            $value = $stored[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $config->set($meta['config'], $this->cast($value, $meta['cast'] ?? 'string'));
        }
    }

    protected function cast(string $value, string $cast): mixed
    {
        return match ($cast) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'float' => (float) $value,
            default => $value,
        };
    }
}
