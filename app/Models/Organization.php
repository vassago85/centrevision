<?php

namespace App\Models;

use App\Enums\OrganizationType;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Either a property owner (which holds sites) or a shop (which sits inside
 * exactly one site).
 *
 * @property int $id
 * @property string $name
 * @property OrganizationType $type
 * @property int|null $parent_site_id
 * @property int|null $referred_by_partner_id
 * @property array<string, mixed>|null $settings
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'type', 'parent_site_id', 'referred_by_partner_id', 'settings'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Revenue-share defaults, overridable per owner organization in Settings.
     */
    public const DEFAULT_SETTINGS = [
        // Share of each shop's monthly fee the platform retains.
        'platform_shop_revenue_share' => 0.30,
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'settings' => 'array',
        ];
    }

    public function isOwner(): bool
    {
        return $this->type === OrganizationType::Owner;
    }

    public function isShop(): bool
    {
        return $this->type === OrganizationType::Shop;
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * The site this shop trades in. Null for owner organizations.
     *
     * @return BelongsTo<Site, $this>
     */
    public function parentSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'parent_site_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function referredByPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'referred_by_partner_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasOne<ShopSubscription, $this>
     */
    public function shopSubscription(): HasOne
    {
        return $this->hasOne(ShopSubscription::class);
    }

    /**
     * @return MorphMany<Invoice, $this>
     */
    public function invoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'billable');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default ?? data_get(self::DEFAULT_SETTINGS, $key));
    }

    /**
     * The partner earning commission on this organization. Shops inherit the
     * partner of the owner whose site they sit in.
     */
    public function commissionPartner(): ?Partner
    {
        if ($this->referred_by_partner_id !== null) {
            return $this->referredByPartner;
        }

        return $this->parentSite?->organization?->referredByPartner;
    }
}
