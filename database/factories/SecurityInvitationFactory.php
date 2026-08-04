<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SecurityInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityInvitation>
 */
class SecurityInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->owner(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'token' => SecurityInvitation::generateToken(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'accepted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
