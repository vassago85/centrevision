<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'organization_id' => Organization::factory()->owner(),
            'role' => UserRole::OwnerAdmin,
        ];
    }

    public function ownerAdmin(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::OwnerAdmin,
            'organization_id' => $organization?->getKey() ?? Organization::factory()->owner(),
        ]);
    }

    /**
     * A guard or other on-the-ground security staffer hired by an owner.
     * Shares the owner's organization so the site scope carries across
     * automatically.
     */
    public function securityOperator(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::SecurityOperator,
            'organization_id' => $organization?->getKey() ?? Organization::factory()->owner(),
            'alert_email_opt_in' => true,
        ]);
    }

    public function shopAdmin(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::ShopAdmin,
            'organization_id' => $organization?->getKey() ?? Organization::factory()->shop(),
        ]);
    }

    public function shopViewer(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::ShopViewer,
            'organization_id' => $organization?->getKey() ?? Organization::factory()->shop(),
        ]);
    }

    /**
     * Platform admins sit above every tenant and so hold no organization.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::PlatformAdmin,
            'organization_id' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
