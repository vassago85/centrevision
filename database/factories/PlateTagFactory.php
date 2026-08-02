<?php

namespace Database\Factories;

use App\Enums\PlateTagType;
use App\Models\PlateTag;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlateTag>
 */
class PlateTagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'plate_number' => PlateEventFactory::plate(),
            'tag' => PlateTagType::RecurringPattern,
            'tagged_at' => now(),
            'evidence' => null,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn () => ['tag' => PlateTagType::RecurringPattern]);
    }
}
