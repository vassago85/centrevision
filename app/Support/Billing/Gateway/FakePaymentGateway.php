<?php

namespace App\Support\Billing\Gateway;

use App\Models\Invoice;

/**
 * Stands in for a real provider in tests and local development, where there
 * are no Paystack keys and nobody wants a live charge.
 *
 * Every checkout it hands out is remembered, so a test can assert what was
 * requested and then decide whether the payment succeeded.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, array{invoice_id: int, amount: float, email: string}> */
    public array $checkouts = [];

    /** @var array<string, bool> */
    protected array $outcomes = [];

    public bool $signatureIsValid = true;

    public function createCheckout(Invoice $invoice, string $email, string $callbackUrl): Checkout
    {
        $reference = 'fake-'.$invoice->number.'-'.(count($this->checkouts) + 1);

        $this->checkouts[$reference] = [
            'invoice_id' => $invoice->getKey(),
            'amount' => (float) $invoice->amount,
            'email' => $email,
        ];

        return new Checkout($reference, $callbackUrl.'?reference='.$reference);
    }

    public function verify(string $reference): PaymentResult
    {
        if (! isset($this->checkouts[$reference])) {
            return PaymentResult::failed($reference, 'Unknown reference.');
        }

        // Unless a test says otherwise, the payment went through.
        $successful = $this->outcomes[$reference] ?? true;

        return new PaymentResult(
            reference: $reference,
            successful: $successful,
            amount: $successful ? $this->checkouts[$reference]['amount'] : 0.0,
            message: $successful ? null : 'Declined by the fake gateway.',
        );
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        return $this->signatureIsValid;
    }

    public function parseWebhook(array $payload): ?PaymentResult
    {
        if (($payload['event'] ?? null) !== 'charge.success') {
            return null;
        }

        return $this->verify($payload['data']['reference'] ?? '');
    }

    /**
     * Make the next verification of this reference fail.
     */
    public function willDecline(string $reference): static
    {
        $this->outcomes[$reference] = false;

        return $this;
    }
}
