<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\ShopSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopSubscription>
 */
class ShopSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->shop(),
            'monthly_amount' => config('trafficflow.shop_monthly_amount_default'),
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->endOfMonth(),
        ];
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

    public function trialing(): static
    {
        return $this->status(SubscriptionStatus::Trialing);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn () => ['monthly_amount' => $amount]);
    }
}
