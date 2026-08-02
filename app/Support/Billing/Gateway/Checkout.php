<?php

namespace App\Support\Billing\Gateway;

/**
 * A hosted payment page the payer must be redirected to.
 */
class Checkout
{
    public function __construct(
        public readonly string $reference,
        public readonly string $url,
    ) {}
}
