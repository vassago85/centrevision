<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteDayStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteDayStat>
 */
class SiteDayStatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'local_date' => now()->subDay()->toDateString(),
            'visits_count' => fake()->numberBetween(50, 400),
            'unique_vehicles' => fake()->numberBetween(30, 250),
            'temp_avg_c' => fake()->randomFloat(2, 5, 35),
            'precip_mm' => 0,
            'weather_code' => 0,
            'weather_label' => 'Clear',
            'is_public_holiday' => false,
            'is_school_holiday' => false,
            'holiday_name' => null,
        ];
    }

    public function publicHoliday(string $name = 'Freedom Day'): static
    {
        return $this->state(fn () => [
            'is_public_holiday' => true,
            'holiday_name' => $name,
        ]);
    }

    public function schoolHoliday(): static
    {
        return $this->state(fn () => [
            'is_school_holiday' => true,
        ]);
    }

    public function rainy(): static
    {
        return $this->state(fn () => [
            'precip_mm' => fake()->randomFloat(2, 3, 25),
            'weather_code' => 61,
            'weather_label' => 'Rain',
        ]);
    }
}
