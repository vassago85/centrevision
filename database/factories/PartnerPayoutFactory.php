<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Partner;
use App\Models\PartnerPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerPayout>
 */
class PartnerPayoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now()->subMonth()->startOfMonth();
        $revenue = fake()->randomFloat(2, 2000, 30000);
        $rate = config('trafficflow.partner_commission_rate');

        return [
            'partner_id' => Partner::factory(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
            'revenue_base' => $revenue,
            'commission_rate' => $rate,
            'commission_amount' => round($revenue * $rate, 2),
            'status' => PayoutStatus::Pending,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
