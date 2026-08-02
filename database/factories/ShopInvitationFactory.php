<?php

namespace Database\Factories;

use App\Models\ShopInvitation;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopInvitation>
 */
class ShopInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'shop_name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'token' => ShopInvitation::generateToken(),
            'monthly_amount' => config('trafficflow.shop_monthly_amount_default'),
            'expires_at' => now()->addDays((int) config('trafficflow.shop_invitation_expires_days')),
            'accepted_at' => null,
            'organization_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }
}
