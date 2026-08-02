<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Scopes\SiteScope;
use App\Models\ShopSubscription;
use App\Models\SiteSubscription;
use App\Support\Billing\Gateway\Checkout;
use App\Support\Billing\Gateway\PaymentGateway;
use App\Support\Billing\Gateway\PaymentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves an invoice between "we asked for money" and "the money arrived".
 *
 * The application never marks an invoice paid on the strength of a redirect;
 * it only does so after the gateway itself confirms the reference, either on
 * the callback or via a signed webhook. Both paths land here, and settling
 * twice is harmless.
 */
class PaymentProcessor
{
    public function __construct(protected PaymentGateway $gateway) {}

    public function startCheckout(Invoice $invoice, string $email, string $callbackUrl): Checkout
    {
        $checkout = $this->gateway->createCheckout($invoice, $email, $callbackUrl);

        $invoice->update([
            'gateway_reference' => $checkout->reference,
            'status' => InvoiceStatus::Pending,
        ]);

        return $checkout;
    }

    /**
     * Confirm a reference with the gateway and apply the outcome.
     */
    public function settleReference(string $reference): ?Invoice
    {
        return $this->settle($this->gateway->verify($reference));
    }

    public function settle(PaymentResult $result): ?Invoice
    {
        $invoice = Invoice::query()->where('gateway_reference', $result->reference)->first();

        if ($invoice === null) {
            Log::warning('Payment for an unknown reference', ['reference' => $result->reference]);

            return null;
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            return $invoice;
        }

        if (! $result->successful) {
            $invoice->update(['status' => InvoiceStatus::Failed]);

            return $invoice;
        }

        // A short payment is not a paid invoice; flagging it as failed keeps
        // the tenant chased rather than silently written off.
        if (round($result->amount, 2) + 0.001 < round((float) $invoice->amount, 2)) {
            Log::warning('Underpaid invoice', [
                'invoice' => $invoice->number,
                'expected' => (float) $invoice->amount,
                'received' => $result->amount,
            ]);

            $invoice->update(['status' => InvoiceStatus::Failed]);

            return $invoice;
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ]);

            $this->reactivate($invoice);

            return $invoice;
        });
    }

    /**
     * Payment is what lifts the paywall, so the subscriptions the invoice
     * covers are pushed to active with a fresh period.
     */
    protected function reactivate(Invoice $invoice): void
    {
        $billable = $invoice->billable;

        if (! $billable instanceof Organization) {
            return;
        }

        $periodEnd = $invoice->period_end->copy()->addMonthNoOverflow()->endOfMonth();

        if ($billable->isShop()) {
            ShopSubscription::query()
                ->where('organization_id', $billable->getKey())
                ->update([
                    'status' => SubscriptionStatus::Active,
                    'current_period_ends_at' => $periodEnd,
                ]);

            return;
        }

        SiteSubscription::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $billable->sites()->select('id'))
            ->update([
                'status' => SubscriptionStatus::Active,
                'current_period_ends_at' => $periodEnd,
            ]);
    }
}
