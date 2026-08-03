<?php

namespace Database\Factories;

use App\Enums\CameraRole;
use App\Enums\IngestionMode;
use App\Models\Camera;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Camera>
 */
class CameraFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->randomElement(['North', 'South', 'East', 'West']).' '
                .fake()->randomElement(['entrance', 'exit', 'lane']),
            'role' => CameraRole::Both,
            'ip_address' => fake()->unique()->localIpv4(),
            'isapi_username' => 'admin',
            'isapi_password' => 'camera-secret',
            'channel_id' => 1,
            'is_active' => true,
            // Factory-built cameras predate any camera-side setup, so keep
            // them on the legacy stream mode by default. Tests that exercise
            // the webhook path opt in with ->webhook().
            'ingestion_mode' => IngestionMode::Stream,
            'webhook_secret' => 'test-webhook-secret',
        ];
    }

    public function webhook(?string $secret = null): static
    {
        return $this->state(fn () => [
            'ingestion_mode' => IngestionMode::Webhook,
            'webhook_secret' => $secret ?? 'test-webhook-secret',
        ]);
    }

    public function entrance(): static
    {
        return $this->state(fn () => [
            'role' => CameraRole::Entrance,
            'name' => fake()->randomElement(['North', 'South', 'East', 'West']).' entrance',
        ]);
    }

    public function exit(): static
    {
        return $this->state(fn () => [
            'role' => CameraRole::Exit,
            'name' => fake()->randomElement(['North', 'South', 'East', 'West']).' exit',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
