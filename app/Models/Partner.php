<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An installer or reseller who referred one or more owner organizations.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property float $commission_rate
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email', 'commission_rate'])]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:6',
        ];
    }

    /**
     * What this partner is owed of an invoiced amount, using their split.
     * Stephan's 1/3 deal is stored as 0.333333 so R1,500 rounds to R500.
     */
    public function shareOf(float $amount): float
    {
        return round($amount * (float) $this->commission_rate, 2);
    }

    /**
     * Owner organizations referred by this partner. Every site and shop under
     * them counts towards commission, with no per-shop tracking needed.
     *
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'referred_by_partner_id');
    }

    /**
     * @return HasMany<PartnerPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(PartnerPayout::class);
    }

    /**
     * Sites whose handshake names this partner, independent of which owner
     * they sit under.
     *
     * @return HasMany<SiteSubscription, $this>
     */
    public function siteSubscriptions(): HasMany
    {
        return $this->hasMany(SiteSubscription::class);
    }
}
