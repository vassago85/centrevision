<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->owner(),
            'name' => fake()->streetName().' Mall',
            'address' => fake()->streetAddress().', '.fake()->city(),
            'settings' => null,
        ];
    }

    public function for_(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function settings(array $settings): static
    {
        return $this->state(fn () => ['settings' => $settings]);
    }
}
