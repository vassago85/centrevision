<?php

namespace App\Support\Billing;

use App\Enums\BaseTier;
use App\Models\Site;

/**
 * What one site costs its owner for a month, broken into the parts that appear
 * as invoice lines.
 */
class SiteCharge
{
    public function __construct(
        public readonly Site $site,
        public readonly BaseTier $tier,
        public readonly int $cameraCount,
        public readonly int $payingShopCount,
        public readonly float $baseFee,
        public readonly float $cameraSurcharge,
        public readonly float $variableFee,
        public readonly float $uncappedVariableFee,
        public readonly ?float $variableFeeCap,
    ) {}

    public function total(): float
    {
        return round($this->baseFee + $this->cameraSurcharge + $this->variableFee, 2);
    }

    public function wasCapped(): bool
    {
        return $this->variableFeeCap !== null
            && $this->uncappedVariableFee > $this->variableFee;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'tier' => $this->tier->value,
            'cameras' => $this->cameraCount,
            'paying_shops' => $this->payingShopCount,
            'variable_fee_capped' => $this->wasCapped(),
        ];
    }
}
