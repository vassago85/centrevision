<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\ShopSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's flat monthly fee, billed by the platform on the owner's behalf.
 *
 * @property int $id
 * @property int $organization_id
 * @property float $monthly_amount
 * @property SubscriptionStatus $status
 * @property CarbonInterface|null $current_period_ends_at
 */
#[Fillable([
    'organization_id', 'monthly_amount', 'status', 'current_period_ends_at',
    'gateway_customer_id', 'gateway_subscription_id',
])]
class ShopSubscription extends Model
{
    /** @use HasFactory<ShopSubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
            'status' => SubscriptionStatus::class,
            'current_period_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }
}
