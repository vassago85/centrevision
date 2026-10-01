<?php

namespace Database\Factories;

use App\Enums\BaseTier;
use App\Enums\SubscriptionStatus;
use App\Models\Site;
use App\Models\SiteSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteSubscription>
 */
class SiteSubscriptionFactory extends Factory
{
    /**
     * Representative per-tier handshake amounts used by tests, factories, and
     * the demo seeder. Production pricing is bespoke and set per site by a
     * Platform admin — these values are only here so the test suite and the
     * demo seed have realistic Rand figures to render.
     */
    private const FIXTURE_FEES = [
        BaseTier::Starter->value => 1800.00,
        BaseTier::Standard->value => 3200.00,
        BaseTier::Large->value => 5500.00,
        BaseTier::Enterprise->value => 5500.00,
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'base_tier' => BaseTier::Starter,
            // Zero means "no handshake" — BillingCalculator then uses the
            // owner-wide override or falls through to R0 (all pricing is
            // bespoke). A positive base_fee is a per-site agreement.
            'base_fee' => 0,
            'variable_rate_per_camera_per_subuser' => config('trafficflow.variable_rate_per_camera_per_subuser'),
            'variable_fee_cap' => null,
            'partner_id' => null,
            'partner_amount' => 0,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->endOfMonth(),
        ];
    }

    /**
     * Stamp a representative handshake fee for the given tier bracket. Only
     * used by tests and the demo seeder; production handshakes are set by
     * Platform admins through the Owners UI, not derived from the tier.
     */
    public function tier(BaseTier $tier): static
    {
        return $this->state(fn () => [
            'base_tier' => $tier,
            'base_fee' => self::fixtureFeeFor($tier),
        ]);
    }

    public static function fixtureFeeFor(BaseTier $tier): float
    {
        return self::FIXTURE_FEES[$tier->value];
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function pastDue(): static
    {
        return $this->status(SubscriptionStatus::PastDue);
    }

    public function canceled(): static
    {
        return $this->status(SubscriptionStatus::Canceled);
    }

    public function cappedAt(float $cap): static
    {
        return $this->state(fn () => ['variable_fee_cap' => $cap]);
    }
}
