<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Carbon\CarbonInterface;
use Database\Factories\PartnerPayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission owed to a partner for one billing period.
 *
 * @property int $id
 * @property int $partner_id
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property float $revenue_base
 * @property float $commission_rate
 * @property float $commission_amount
 * @property PayoutStatus $status
 * @property CarbonInterface|null $paid_at
 */
#[Fillable([
    'partner_id', 'period_start', 'period_end', 'revenue_base',
    'commission_rate', 'commission_amount', 'status', 'paid_at',
])]
class PartnerPayout extends Model
{
    /** @use HasFactory<PartnerPayoutFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'revenue_base' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'status' => PayoutStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
