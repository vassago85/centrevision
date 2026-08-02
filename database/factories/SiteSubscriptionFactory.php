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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'base_tier' => BaseTier::Starter,
            'base_fee' => BaseTier::Starter->baseFee(),
            'variable_rate_per_camera_per_subuser' => config('trafficflow.variable_rate_per_camera_per_subuser'),
            'variable_fee_cap' => null,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->endOfMonth(),
        ];
    }

    public function tier(BaseTier $tier): static
    {
        return $this->state(fn () => [
            'base_tier' => $tier,
            'base_fee' => $tier->baseFee(),
        ]);
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
