<?php

namespace App\Http\Controllers\Billing;

use App\Support\Billing\PaymentProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the gateway sends the payer back to. The reference on the query string
 * is untrusted; it is only ever used to ask the gateway what really happened.
 */
class PaymentCallbackController
{
    public function __invoke(Request $request, PaymentProcessor $processor): RedirectResponse
    {
        $reference = $request->string('reference')->toString();

        $invoice = $reference === '' ? null : $processor->settleReference($reference);

        if ($invoice?->status->isSettled()) {
            return redirect()->route('billing')
                ->with('status', 'Payment received. Invoice '.$invoice->number.' is settled.');
        }

        return redirect()->route('billing')
            ->with('status', 'We could not confirm that payment. If you were charged, it will settle shortly.');
    }
}
