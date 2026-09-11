<?php

namespace App\Support\Platform;

use App\Models\Organization;
use App\Models\Partner;
use Carbon\CarbonInterface;

/**
 * One owner organization as the platform sees it.
 */
class OwnerSummary
{
    public function __construct(
        public readonly Organization $organization,
        public readonly int $siteCount,
        public readonly int $cameraCount,
        public readonly int $payingShopCount,
        public readonly float $monthlyCharge,
        public readonly float $platformShopShare,
        public readonly bool $lapsed,
        public readonly ?Partner $partner,
        public readonly bool $isFree = false,
        public readonly bool $hasCustomPlan = false,
        public readonly ?CarbonInterface $lastLoginAt = null,
        public readonly bool $canImpersonate = false,
    ) {}

    /**
     * What this owner is worth to the platform each month.
     */
    public function totalToPlatform(): float
    {
        return round($this->monthlyCharge + $this->platformShopShare, 2);
    }

    /**
     * Tone hint for the "Last login" pill on the Owners table. Fresh (green)
     * within a day, warm (default) within a week, muted after that, danger
     * when nobody in the org has ever logged in.
     */
    public function loginTone(): string
    {
        if ($this->lastLoginAt === null) {
            return 'danger';
        }

        $hours = $this->lastLoginAt->diffInHours(now(), absolute: true);

        return match (true) {
            $hours <= 24 => 'positive',
            $hours <= 24 * 7 => 'default',
            default => 'muted',
        };
    }

    public function loginLabel(): string
    {
        return $this->lastLoginAt === null
            ? 'Never'
            : $this->lastLoginAt->diffForHumans(short: true);
    }
}
