<?php

namespace Database\Factories;

use App\Enums\WatchlistKind;
use App\Models\Site;
use App\Models\WatchlistPlate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchlistPlate>
 */
class WatchlistPlateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'plate_number' => PlateEventFactory::plate(),
            'kind' => WatchlistKind::Watch,
            'reason' => null,
            'expires_at' => null,
            'added_by_user_id' => null,
        ];
    }

    public function block(): static
    {
        return $this->state(fn () => ['kind' => WatchlistKind::Block]);
    }

    public function watch(): static
    {
        return $this->state(fn () => ['kind' => WatchlistKind::Watch]);
    }

    public function vip(): static
    {
        return $this->state(fn () => ['kind' => WatchlistKind::Vip]);
    }
}
