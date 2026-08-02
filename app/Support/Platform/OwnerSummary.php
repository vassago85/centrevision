<?php

namespace App\Support\Platform;

use App\Models\Organization;
use App\Models\Partner;

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
    ) {}

    /**
     * What this owner is worth to the platform each month.
     */
    public function totalToPlatform(): float
    {
        return round($this->monthlyCharge + $this->platformShopShare, 2);
    }
}
