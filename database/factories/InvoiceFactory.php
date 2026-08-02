<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now()->startOfMonth();

        return [
            'billable_type' => Organization::class,
            'billable_id' => Organization::factory(),
            'number' => 'TF-'.$periodStart->format('Ym').'-'.fake()->unique()->numerify('####'),
            'amount' => fake()->randomFloat(2, 500, 12000),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
            'status' => InvoiceStatus::Pending,
            'paid_at' => null,
        ];
    }

    public function for_(Model $billable): static
    {
        return $this->state(fn () => [
            'billable_type' => $billable::class,
            'billable_id' => $billable->getKey(),
        ]);
    }

    public function paid(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => $at ?? now(),
        ]);
    }

    public function period(CarbonInterface $start, CarbonInterface $end): static
    {
        return $this->state(fn () => [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ]);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }
}
