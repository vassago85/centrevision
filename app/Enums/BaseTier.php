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

    public function baseFee(): float
    {
        return match ($this) {
            self::Starter => 1800.00,
            self::Standard => 3200.00,
            self::Large => 5500.00,
            self::Enterprise => 5500.00,
        };
    }

    /**
     * Enterprise sites pay a per-camera surcharge above the Large ceiling.
     */
    public function perCameraSurchargeAbove(): ?int
    {
        return $this === self::Enterprise ? 16 : null;
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
