<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Support\Billing\Gateway\FakePaymentGateway;
use App\Support\Billing\Gateway\PaymentGateway;
use App\Support\Billing\Gateway\PaymentResult;
use App\Support\Billing\PaymentProcessor;

beforeEach(function () {
    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();

    $this->subscription = SiteSubscription::factory()
        ->for($this->site)
        ->pastDue()
        ->create(['current_period_ends_at' => now()->subMonth()]);

    $this->invoice = Invoice::factory()->for_($this->owner)->create([
        'amount' => 1800.00,
        'status' => InvoiceStatus::Pending,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $this->processor = app(PaymentProcessor::class);
});

it('records the gateway reference when checkout starts', function () {
    $checkout = $this->processor->startCheckout($this->invoice, 'owner@example.com', 'https://app.test/callback');

    expect($this->invoice->fresh()->gateway_reference)->toBe($checkout->reference)
        ->and($this->gateway->checkouts[$checkout->reference]['amount'])->toBe(1800.00);
});

it('settles the invoice and lifts the paywall when payment succeeds', function () {
    $checkout = $this->processor->startCheckout($this->invoice, 'owner@example.com', 'https://app.test/callback');

    $this->processor->settleReference($checkout->reference);

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->fresh()->paid_at)->not->toBeNull()
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->subscription->fresh()->current_period_ends_at->isFuture())->toBeTrue();
});

it('marks the invoice failed when the gateway declines', function () {
    $checkout = $this->processor->startCheckout($this->invoice, 'owner@example.com', 'https://app.test/callback');

    $this->gateway->willDecline($checkout->reference);
    $this->processor->settleReference($checkout->reference);

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Failed)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('refuses to settle an underpayment', function () {
    $this->invoice->update(['gateway_reference' => 'ref-short']);

    $this->processor->settle(new PaymentResult('ref-short', true, 1000.00));

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Failed);
});

it('ignores a reference it does not recognise', function () {
    $this->processor->settle(new PaymentResult('who-knows', true, 1800.00));

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

it('does not re-settle an invoice that is already paid', function () {
    $paidAt = now()->subDays(3);

    $this->invoice->update([
        'gateway_reference' => 'ref-paid',
        'status' => InvoiceStatus::Paid,
        'paid_at' => $paidAt,
    ]);

    $this->processor->settle(new PaymentResult('ref-paid', true, 1800.00));

    expect($this->invoice->fresh()->paid_at->toDateTimeString())->toBe($paidAt->toDateTimeString());
});

it('reactivates a shop subscription rather than the owner sites', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    $shopSubscription = ShopSubscription::factory()->for($shop, 'organization')->pastDue()->create();

    $invoice = Invoice::factory()->for_($shop)->create([
        'amount' => 400.00,
        'status' => InvoiceStatus::Pending,
        'gateway_reference' => 'ref-shop',
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $this->processor->settle(new PaymentResult('ref-shop', true, 400.00));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($shopSubscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});
