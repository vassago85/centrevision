<?php

namespace App\Models;

use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use Carbon\CarbonInterface;
use Database\Factories\ShopInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An owner's invitation for a shop to open a sub-account on one of its sites.
 *
 * @property int $id
 * @property int $site_id
 * @property string $shop_name
 * @property string $email
 * @property string $token
 * @property float $monthly_amount
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $organization_id
 */
#[Fillable([
    'site_id', 'shop_name', 'email', 'token', 'monthly_amount',
    'expires_at', 'accepted_at', 'organization_id',
])]
#[ScopedBy(SiteScope::class)]
class ShopInvitation extends Model implements SiteScoped
{
    /** @use HasFactory<ShopInvitationFactory> */
    use HasFactory, ScopedToSite;

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<ShopInvitation>  $query
     * @return Builder<ShopInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
