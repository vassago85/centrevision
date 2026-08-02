<?php

namespace App\Support\Billing\Gateway;

/**
 * The outcome of a payment, as the provider reports it.
 */
class PaymentResult
{
    public function __construct(
        public readonly string $reference,
        public readonly bool $successful,
        public readonly float $amount,
        public readonly string $currency = 'ZAR',
        public readonly ?string $message = null,
    ) {}

    public static function failed(string $reference, ?string $message = null): self
    {
        return new self($reference, false, 0.0, config('trafficflow.currency'), $message);
    }
}
