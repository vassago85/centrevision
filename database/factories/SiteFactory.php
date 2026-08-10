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
            // Default new sites to Pretoria so weather/holiday enrichment
            // tests have coordinates + a South African calendar to work with
            // without every test having to set them explicitly.
            'latitude' => -25.7479,
            'longitude' => 28.2293,
            'country_code' => 'ZA',
            'timezone' => 'Africa/Johannesburg',
            'settings' => null,
        ];
    }

    /**
     * Drop the default coordinates — useful for tests that need to exercise
     * the "no location" fallback path (no weather markers, no errors).
     */
    public function withoutCoordinates(): static
    {
        return $this->state(fn () => [
            'latitude' => null,
            'longitude' => null,
        ]);
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
