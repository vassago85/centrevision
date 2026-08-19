<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Models\Scopes\SiteScope;
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
 * @property CarbonInterface|null $approved_at
 * @property int|null $approved_by_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name', 'type', 'parent_site_id', 'referred_by_partner_id',
    'settings', 'approved_at', 'approved_by_user_id',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Revenue-share and billing defaults, overridable per owner organization
     * from the platform admin's Owners page. Only the keys listed here have
     * meaning — arbitrary values written to `settings` are ignored by the
     * billing pipeline.
     */
    public const DEFAULT_SETTINGS = [
        'platform_shop_revenue_share' => 0.30,

        // Per-owner billing overrides applied by BillingCalculator. `free`
        // short-circuits every fee (base, camera surcharge, variable, seat)
        // to zero. The three `*_override` values, when set to a positive
        // number, replace the published tier on sites that have no handshake.
        // A positive SiteSubscription.base_fee is a per-site agreement and
        // beats these owner-wide numbers. `notes` is a free-text reminder
        // shown to platform admins ("6-month pilot for X.").
        'billing' => [
            'free' => false,
            'base_fee_override' => null,
            'variable_rate_override' => null,
            'variable_fee_cap_override' => null,
            'notes' => '',
        ],
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'settings' => 'array',
            'approved_at' => 'datetime',
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
     * True when the organization has been signed off by a platform admin.
     * Shops inherit their owner's approval and so always answer true; only
     * owner orgs are actually gated on this timestamp.
     */
    public function isApproved(): bool
    {
        if ($this->isShop()) {
            return $this->parentSite?->organization?->isApproved() ?? true;
        }

        return $this->approved_at !== null;
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
     * True when a platform admin has ticked "Free account" on this owner —
     * BillingCalculator skips every fee (base, variable, seat) in that case.
     */
    public function isOnFreeBillingPlan(): bool
    {
        return (bool) $this->setting('billing.free', false);
    }

    /**
     * True when any of the per-owner billing knobs (free, or one of the
     * *_override values) is set to something meaningful, so the UI can
     * badge the row as "Custom".
     */
    public function hasCustomBillingPlan(): bool
    {
        if ($this->isOnFreeBillingPlan()) {
            return true;
        }

        foreach (['base_fee_override', 'variable_rate_override', 'variable_fee_cap_override'] as $key) {
            $value = $this->setting("billing.{$key}");

            if ($value !== null && $value !== '' && (float) $value > 0.0) {
                return true;
            }
        }

        return $this->hasSiteAgreement();
    }

    /**
     * True when any site under this owner has a handshake (a stored
     * positive base_fee), so the Owners table can badge the row Custom
     * even when the owner-wide overrides are empty.
     */
    public function hasSiteAgreement(): bool
    {
        return SiteSubscription::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $this->sites()->select('id'))
            ->where('base_fee', '>', 0)
            ->exists();
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
