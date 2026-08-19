<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Security Systems',
            'email' => fake()->unique()->companyEmail(),
            'commission_rate' => config('trafficflow.partner_commission_rate'),
        ];
    }

    public function commission(float $rate): static
    {
        return $this->state(fn () => ['commission_rate' => $rate]);
    }

    /**
     * The standing installer split: partner 1/3, platform 2/3.
     */
    public function thirdShare(): static
    {
        return $this->commission(0.333333);
    }
}
