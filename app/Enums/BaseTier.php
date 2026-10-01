<?php

namespace App\Enums;

enum BaseTier: string
{
    case Starter = 'starter';
    case Standard = 'standard';
    case Large = 'large';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Starter',
            self::Standard => 'Standard',
            self::Large => 'Large',
            self::Enterprise => 'Enterprise',
        };
    }

    /**
     * Inclusive upper bound on camera count, or null for the open-ended tier.
     */
    public function cameraCeiling(): ?int
    {
        return match ($this) {
            self::Starter => 4,
            self::Standard => 8,
            self::Large => 16,
            self::Enterprise => null,
        };
    }

    /**
     * All pricing is bespoke — a site is billed R0 for its base unless
     * either a per-site SiteSubscription handshake or a per-owner
     * `billing.base_fee_override` has been set in Platform admin. The tier
     * itself is kept purely as a size bracket shown on the sites grid and
     * invoice lines; it no longer carries a Rand amount.
     */
    public function baseFee(): float
    {
        return 0.00;
    }

    /**
     * No automatic per-camera surcharge on metered sites — bespoke pricing
     * means the per-site handshake carries any extra-camera arithmetic.
     * Handshake sites still get the per-camera scaling above the Starter
     * ceiling, handled inside BillingCalculator::cameraSurcharge().
     */
    public function perCameraSurchargeAbove(): ?int
    {
        return null;
    }

    public const ENTERPRISE_PER_CAMERA_FEE = 300.00;

    public static function forCameraCount(int $cameras): self
    {
        return match (true) {
            $cameras <= 4 => self::Starter,
            $cameras <= 8 => self::Standard,
            $cameras <= 16 => self::Large,
            default => self::Enterprise,
        };
    }
}
