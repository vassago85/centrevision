<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Billed to an owner organization (one consolidated invoice across its sites)
 * or to a shop organization.
 *
 * @property int $id
 * @property string $billable_type
 * @property int $billable_id
 * @property string $number
 * @property float $amount
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property InvoiceStatus $status
 * @property CarbonInterface|null $paid_at
 */
#[Fillable([
    'billable_type', 'billable_id', 'number', 'amount',
    'period_start', 'period_end', 'status', 'paid_at', 'gateway_reference',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => InvoiceStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Paid);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeForPeriod(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('period_start', '>=', $start->toDateString())
            ->where('period_end', '<=', $end->toDateString());
    }

    public static function nextNumber(CarbonInterface $periodStart): string
    {
        $prefix = 'TF-'.$periodStart->format('Ym');

        $sequence = static::query()
            ->where('number', 'like', $prefix.'%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $sequence);
    }
}
