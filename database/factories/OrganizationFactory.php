<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Properties',
            'type' => OrganizationType::Owner,
            'parent_site_id' => null,
            'referred_by_partner_id' => null,
            'settings' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'type' => OrganizationType::Owner,
            'parent_site_id' => null,
        ]);
    }

    public function shop(?Site $site = null): static
    {
        return $this->state(fn () => [
            'name' => fake()->company(),
            'type' => OrganizationType::Shop,
            'parent_site_id' => $site?->getKey() ?? Site::factory(),
        ]);
    }

    public function referredBy(Partner|int $partner): static
    {
        return $this->state(fn () => [
            'referred_by_partner_id' => $partner instanceof Partner ? $partner->getKey() : $partner,
        ]);
    }
}
