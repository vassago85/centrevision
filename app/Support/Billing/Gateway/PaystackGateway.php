<?php

namespace App\Support\Billing\Gateway;

use App\Models\Invoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack, over its REST API.
 *
 * Paystack works in the smallest currency unit, so every amount is multiplied
 * by 100 on the way out and divided by 100 on the way back. Getting that wrong
 * in either direction is a hundredfold billing error, which is why the
 * conversion lives in exactly these two places.
 */
class PaystackGateway implements PaymentGateway
{
    protected const SUBUNIT = 100;

    public function __construct(
        protected string $secretKey,
        protected string $baseUrl = 'https://api.paystack.co',
    ) {}

    public function createCheckout(Invoice $invoice, string $email, string $callbackUrl): Checkout
    {
        $response = $this->client()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => (int) round((float) $invoice->amount * self::SUBUNIT),
            'currency' => config('trafficflow.currency'),
            'reference' => $invoice->number.'-'.now()->format('YmdHis'),
            'callback_url' => $callbackUrl,
            'metadata' => [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->number,
            ],
        ]);

        $data = $this->unwrap($response->json(), 'initialize the payment');

        return new Checkout(
            reference: $data['reference'],
            url: $data['authorization_url'],
        );
    }

    public function verify(string $reference): PaymentResult
    {
        $response = $this->client()->get('/transaction/verify/'.urlencode($reference));

        if ($response->failed()) {
            return PaymentResult::failed($reference, 'Paystack could not be reached.');
        }

        $data = $response->json('data') ?? [];

        return $this->resultFrom($reference, $data);
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, $this->secretKey), $signature);
    }

    public function parseWebhook(array $payload): ?PaymentResult
    {
        // Paystack sends many event types; only completed charges change what
        // we believe about an invoice.
        if (($payload['event'] ?? null) !== 'charge.success') {
            return null;
        }

        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;

        if (! is_string($reference)) {
            return null;
        }

        return $this->resultFrom($reference, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resultFrom(string $reference, array $data): PaymentResult
    {
        $successful = ($data['status'] ?? null) === 'success';

        return new PaymentResult(
            reference: $reference,
            successful: $successful,
            amount: ((int) ($data['amount'] ?? 0)) / self::SUBUNIT,
            currency: $data['currency'] ?? config('trafficflow.currency'),
            message: $successful ? null : ($data['gateway_response'] ?? 'Payment was not completed.'),
        );
    }

    /**
     * Paystack reports its own failures inside a 200 response, so a successful
     * HTTP call is not the same as a successful request.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function unwrap(?array $body, string $action): array
    {
        if (($body['status'] ?? false) !== true) {
            throw new RuntimeException('Could not '.$action.': '.($body['message'] ?? 'unknown Paystack error.'));
        }

        return $body['data'];
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(20)
            // Failures are reported as results rather than thrown: a payment
            // we cannot confirm is not the same as a crash.
            ->retry(2, 200, throw: false);
    }
}
