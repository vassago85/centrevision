<?php

namespace Database\Factories;

use App\Enums\VisitStatus;
use App\Models\Site;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $enteredAt = now()->subMinutes(fake()->numberBetween(30, 60 * 24 * 5));
        $dwell = fake()->numberBetween(8, 150);

        return [
            'site_id' => Site::factory(),
            'plate_number' => PlateEventFactory::plate(),
            'entered_at' => $enteredAt,
            'exited_at' => $enteredAt->copy()->addMinutes($dwell),
            'dwell_minutes' => $dwell,
            'status' => VisitStatus::Closed,
        ];
    }

    public function open(?CarbonInterface $enteredAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'entered_at' => $enteredAt ?? $attributes['entered_at'],
            'exited_at' => null,
            'dwell_minutes' => null,
            'status' => VisitStatus::Open,
        ]);
    }

    public function orphaned(): static
    {
        return $this->state(fn () => [
            'exited_at' => null,
            'dwell_minutes' => null,
            'status' => VisitStatus::Orphaned,
        ]);
    }

    public function dwelling(int $minutes): static
    {
        return $this->state(fn (array $attributes) => [
            'dwell_minutes' => $minutes,
            'exited_at' => Date::parse($attributes['entered_at'])->addMinutes($minutes),
            'status' => VisitStatus::Closed,
        ]);
    }

    public function plateNumber(string $plate): static
    {
        return $this->state(fn () => ['plate_number' => $plate]);
    }
}
