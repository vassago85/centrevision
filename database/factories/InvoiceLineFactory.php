<?php

namespace Database\Factories;

use App\Enums\InvoiceLineKind;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'site_id' => null,
            'kind' => InvoiceLineKind::BaseFee,
            'label' => 'Base fee',
            'amount' => fake()->randomFloat(2, 500, 6000),
            'meta' => null,
        ];
    }

    public function kind(InvoiceLineKind $kind): static
    {
        return $this->state(fn () => [
            'kind' => $kind,
            'label' => $kind->label(),
        ]);
    }
}
