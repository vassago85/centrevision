<?php

namespace App\Support\Billing\Gateway;

use App\Models\Invoice;

/**
 * The payment provider, kept behind an interface so the rest of the app never
 * learns which one we use and tests can swap in a fake.
 *
 * Amounts crossing this boundary are always in major units (rands); each
 * implementation converts to whatever the provider expects.
 */
interface PaymentGateway
{
    /**
     * Start a hosted checkout for an invoice and return where to send the
     * payer.
     */
    public function createCheckout(Invoice $invoice, string $email, string $callbackUrl): Checkout;

    /**
     * Confirm a payment actually happened, by asking the provider rather than
     * trusting whatever came back on the redirect.
     */
    public function verify(string $reference): PaymentResult;

    /**
     * Whether a webhook body genuinely came from the provider.
     */
    public function verifyWebhookSignature(string $payload, ?string $signature): bool;

    /**
     * Translate a webhook body into something the application understands, or
     * null for events we do not act on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ?PaymentResult;
}
