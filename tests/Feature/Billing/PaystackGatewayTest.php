<?php

use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Billing\Gateway\PaystackGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->gateway = new PaystackGateway('sk_test_secret');

    $this->invoice = Invoice::factory()
        ->for_(Organization::factory()->owner()->create())
        ->create(['amount' => 1800.50, 'number' => 'TF-202607-0001']);
});

it('sends the amount in cents and returns the hosted page', function () {
    Http::fake([
        '*/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['reference' => 'psk_123', 'authorization_url' => 'https://checkout.paystack.com/psk_123'],
        ]),
    ]);

    $checkout = $this->gateway->createCheckout($this->invoice, 'owner@example.com', 'https://app.test/callback');

    expect($checkout->reference)->toBe('psk_123')
        ->and($checkout->url)->toBe('https://checkout.paystack.com/psk_123');

    Http::assertSent(fn (Request $request) => $request['amount'] === 180050
        && $request['currency'] === 'ZAR'
        && $request['email'] === 'owner@example.com'
        && $request['metadata']['invoice_number'] === 'TF-202607-0001');
});

it('raises Paystack errors returned inside a 200', function () {
    Http::fake([
        '*/transaction/initialize' => Http::response(['status' => false, 'message' => 'Invalid key']),
    ]);

    expect(fn () => $this->gateway->createCheckout($this->invoice, 'owner@example.com', 'https://app.test/callback'))
        ->toThrow(RuntimeException::class, 'Invalid key');
});

it('converts a verified amount back to rands', function () {
    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'amount' => 180050, 'currency' => 'ZAR'],
        ]),
    ]);

    $result = $this->gateway->verify('psk_123');

    expect($result->successful)->toBeTrue()
        ->and($result->amount)->toBe(1800.50);
});

it('reports an unsuccessful transaction with the gateway reason', function () {
    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'failed', 'amount' => 0, 'gateway_response' => 'Insufficient funds'],
        ]),
    ]);

    $result = $this->gateway->verify('psk_123');

    expect($result->successful)->toBeFalse()
        ->and($result->message)->toBe('Insufficient funds');
});

it('treats an unreachable gateway as an unconfirmed payment', function () {
    Http::fake(['*' => Http::response('', 500)]);

    expect($this->gateway->verify('psk_123')->successful)->toBeFalse();
});

it('accepts only a correctly signed webhook body', function () {
    $payload = json_encode(['event' => 'charge.success']);

    expect($this->gateway->verifyWebhookSignature($payload, hash_hmac('sha512', $payload, 'sk_test_secret')))->toBeTrue()
        ->and($this->gateway->verifyWebhookSignature($payload, 'nonsense'))->toBeFalse()
        ->and($this->gateway->verifyWebhookSignature($payload, null))->toBeFalse();
});

it('acts on charge.success and nothing else', function () {
    $charge = [
        'event' => 'charge.success',
        'data' => ['reference' => 'psk_123', 'status' => 'success', 'amount' => 180050],
    ];

    expect($this->gateway->parseWebhook($charge)->amount)->toBe(1800.50)
        ->and($this->gateway->parseWebhook(['event' => 'subscription.create', 'data' => []]))->toBeNull();
});
