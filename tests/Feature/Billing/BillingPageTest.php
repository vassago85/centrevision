<?php

use App\Enums\InvoiceStatus;
use App\Models\Camera;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Support\Billing\Gateway\FakePaymentGateway;
use App\Support\Billing\Gateway\PaymentGateway;
use App\Support\Billing\PaymentProcessor;
use Livewire\Livewire;

beforeEach(function () {
    // Pin the clock to the start of a month so sites created in the setup
    // are considered "full-period" by the metered calculator — otherwise
    // proration would silently reduce every asserted amount below.
    \Illuminate\Support\Facades\Date::setTestNow('2026-06-01 00:00:00');

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    $this->owner = Organization::factory()->owner()->create(['name' => 'Owner A']);
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    SiteSubscription::factory()->for($this->site)->create();
    Camera::factory()->count(3)->for($this->site)->create();

    $this->user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('shows the current period estimate across the owner sites', function () {
    $second = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);
    Camera::factory()->count(6)->for($second)->create();

    Livewire::test('pages::billing')
        ->assertSee('Mall A')
        ->assertSee('Mall B')
        ->assertSee('R5,000.00');
});

it('counts paying shops in the estimate', function () {
    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->site), 'organization')
        ->create();

    Livewire::test('pages::billing')->assertSee('R54.00');
});

it('sends an unpaid invoice to the gateway', function () {
    $invoice = Invoice::factory()->for_($this->owner)->create([
        'amount' => 1800.00,
        'status' => InvoiceStatus::Pending,
    ]);

    Livewire::test('pages::billing')
        ->call('pay', $invoice->id)
        ->assertRedirect();

    expect($invoice->fresh()->gateway_reference)->not->toBeNull()
        ->and($this->gateway->checkouts)->toHaveCount(1);
});

it('will not start a checkout for another owner invoice', function () {
    $other = Invoice::factory()->for_(Organization::factory()->owner()->create())->create();

    Livewire::test('pages::billing')
        ->call('pay', $other->id)
        ->assertStatus(404);

    expect($this->gateway->checkouts)->toBeEmpty();
});

it('settles the invoice when the payer returns from the gateway', function () {
    $invoice = Invoice::factory()->for_($this->owner)->create([
        'amount' => 1800.00,
        'status' => InvoiceStatus::Pending,
    ]);

    $checkout = app(PaymentProcessor::class)
        ->startCheckout($invoice, $this->user->email, route('billing.callback'));

    $this->get(route('billing.callback', ['reference' => $checkout->reference]))
        ->assertRedirect(route('billing'))
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Payment received'));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('does not settle anything on a callback with no reference', function () {
    $this->get(route('billing.callback'))->assertRedirect(route('billing'));

    expect(Invoice::whereNotNull('paid_at')->count())->toBe(0);
});

it('settles from a signed webhook', function () {
    $invoice = Invoice::factory()->for_($this->owner)->create([
        'amount' => 1800.00,
        'status' => InvoiceStatus::Pending,
    ]);

    $checkout = app(PaymentProcessor::class)
        ->startCheckout($invoice, $this->user->email, route('billing.callback'));

    $this->postJson(route('webhooks.paystack'), [
        'event' => 'charge.success',
        'data' => ['reference' => $checkout->reference],
    ])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('rejects a webhook with a bad signature', function () {
    $invoice = Invoice::factory()->for_($this->owner)->create([
        'amount' => 1800.00,
        'status' => InvoiceStatus::Pending,
        'gateway_reference' => 'ref-unsigned',
    ]);

    $this->gateway->signatureIsValid = false;

    $this->postJson(route('webhooks.paystack'), [
        'event' => 'charge.success',
        'data' => ['reference' => 'ref-unsigned'],
    ])->assertStatus(401);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

it('keeps shop users off the billing page', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    actingAsTenant(User::factory()->shopAdmin($shop)->create());

    $this->get(route('billing'))->assertForbidden();
});
