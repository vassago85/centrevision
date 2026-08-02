<?php

namespace App\Http\Controllers\Billing;

use App\Support\Billing\Gateway\PaymentGateway;
use App\Support\Billing\PaymentProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The authoritative payment path: a payer who closes the tab before the
 * redirect still gets their invoice settled here.
 */
class PaystackWebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentProcessor $processor): Response
    {
        if (! $gateway->verifyWebhookSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            return response('Invalid signature.', 401);
        }

        $result = $gateway->parseWebhook($request->json()->all());

        if ($result !== null) {
            $processor->settle($result);
        }

        // Paystack retries anything that is not a 200, so events we ignore
        // still have to be acknowledged.
        return response('', 200);
    }
}
