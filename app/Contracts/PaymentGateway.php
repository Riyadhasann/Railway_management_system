<?php

namespace App\Contracts;

use App\Data\GatewayResult;
use App\Exceptions\InvalidCallbackException;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * An online payment provider. MockGateway now; bKash, Nagad or SSLCommerz later,
 * swapped in AppServiceProvider without touching PaymentService.
 */
interface PaymentGateway
{
    /**
     * Start paying for an initiated payment. Returns the URL to send the passenger to.
     */
    public function startCheckout(Payment $payment): string;

    /**
     * Read and verify the gateway's callback (signature, status, transaction id).
     *
     * @throws InvalidCallbackException if the request is not a genuine callback
     */
    public function verifyCallback(Request $request): GatewayResult;

    /**
     * Return money for a successful payment.
     */
    public function refund(Payment $payment, int $amountPoisha): GatewayResult;
}
