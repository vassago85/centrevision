<?php

namespace Database\Factories;

use App\Enums\PlateDirection;
use App\Models\Camera;
use App\Models\PlateEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlateEvent>
 */
class PlateEventFactory extends Factory
{
    /**
     * A plausible Gauteng-style plate in normalised form.
     */
    public static function plate(): string
    {
        return fake()->regexify('[A-Z]{2}')
            .fake()->numberBetween(10, 99)
            .fake()->randomElement(['GP', 'NW', 'MP', 'FS', 'WP']);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'camera_id' => Camera::factory(),
            'plate_number' => static::plate(),
            'direction' => fake()->randomElement(PlateDirection::cases()),
            'captured_at' => now()->subMinutes(fake()->numberBetween(0, 600)),
            'confidence' => fake()->randomFloat(2, 0.75, 0.99),
            'raw_payload' => null,
            'processed_at' => null,
        ];
    }

    public function plateNumber(string $plate): static
    {
        return $this->state(fn () => ['plate_number' => $plate]);
    }

    public function entering(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => array_filter([
            'direction' => PlateDirection::In,
            'captured_at' => $at,
        ], fn ($value) => $value !== null));
    }

    public function exiting(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => array_filter([
            'direction' => PlateDirection::Out,
            'captured_at' => $at,
        ], fn ($value) => $value !== null));
    }

    public function at(CarbonInterface $at): static
    {
        return $this->state(fn () => ['captured_at' => $at]);
    }

    public function processed(): static
    {
        return $this->state(fn () => ['processed_at' => now()]);
    }
}
