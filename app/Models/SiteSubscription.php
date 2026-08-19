<?php

namespace App\Models;

use App\Enums\BaseTier;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use Carbon\CarbonInterface;
use Database\Factories\SiteSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an owner pays for one site: a camera-count base fee plus a variable fee
 * driven by how many paying shops that site has.
 *
 * @property int $id
 * @property int $site_id
 * @property BaseTier $base_tier
 * @property float $base_fee
 * @property float $variable_rate_per_camera_per_subuser
 * @property float|null $variable_fee_cap
 * @property int|null $partner_id
 * @property float $partner_amount
 * @property SubscriptionStatus $status
 * @property CarbonInterface|null $current_period_ends_at
 */
#[Fillable([
    'site_id', 'base_tier', 'base_fee', 'variable_rate_per_camera_per_subuser',
    'variable_fee_cap', 'partner_id', 'partner_amount',
    'status', 'current_period_ends_at',
    'gateway_customer_id', 'gateway_subscription_id',
])]
#[ScopedBy(SiteScope::class)]
class SiteSubscription extends Model implements SiteScoped
{
    /** @use HasFactory<SiteSubscriptionFactory> */
    use HasFactory, ScopedToSite;

    protected function casts(): array
    {
        return [
            'base_tier' => BaseTier::class,
            'base_fee' => 'decimal:2',
            'variable_rate_per_camera_per_subuser' => 'decimal:2',
            'variable_fee_cap' => 'decimal:2',
            'partner_amount' => 'decimal:2',
            'status' => SubscriptionStatus::class,
            'current_period_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * A stored positive base_fee is a handshake for this site, not the
     * published tier price. Empty / zero means "meter this site as usual".
     */
    public function hasAgreement(): bool
    {
        return (float) $this->base_fee > 0.0;
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }
}
