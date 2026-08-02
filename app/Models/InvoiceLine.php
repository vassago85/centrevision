<?php

namespace App\Models;

use App\Enums\InvoiceLineKind;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an invoice. Owner invoices carry a line per site.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int|null $site_id
 * @property InvoiceLineKind $kind
 * @property string $label
 * @property float $amount
 * @property array<string, mixed>|null $meta
 */
#[Fillable(['invoice_id', 'site_id', 'kind', 'label', 'amount', 'meta'])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => InvoiceLineKind::class,
            'amount' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
