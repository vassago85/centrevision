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
            // Approved by default so 400-plus existing tests keep exercising
            // "signed-in owner" flows without every one having to stamp the
            // organization. Tests that specifically cover the approval flow
            // pass `pendingApproval()` to opt out.
            'approved_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'type' => OrganizationType::Owner,
            'parent_site_id' => null,
        ]);
    }

    /**
     * A brand-new owner sign-up that has not yet been reviewed. Used by
     * approval-flow tests and by the DemoDataSeeder to keep a "you have
     * pending sign-ups" row on the platform dashboard.
     */
    public function pendingApproval(): static
    {
        return $this->state(fn () => [
            'approved_at' => null,
            'approved_by_user_id' => null,
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
