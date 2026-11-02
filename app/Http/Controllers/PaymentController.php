<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Exceptions\InvalidCallbackException;
use App\Gateways\Payment\MockGateway;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * The mock gateway's pay page (signed link from MockGateway::startCheckout).
     */
    public function mock(string $reference, MockGateway $gateway): View
    {
        $payment = Payment::query()->where('reference', $reference)->with('booking.trainRun.train')->firstOrFail();
        $txnId = $gateway->newTxnId();

        return view('payments.mock', [
            'payment' => $payment,
            'booking' => $payment->booking,
            'buttons' => [
                'success' => $gateway->callbackFields($payment, 'success', $txnId),
                'failed' => $gateway->callbackFields($payment, 'failed', $txnId),
                'cancelled' => $gateway->callbackFields($payment, 'cancelled', $txnId),
            ],
        ]);
    }

    /**
     * Gateway callback. Verified by the gateway, applied by PaymentService, then the
     * passenger is sent back to their booking. Duplicate callbacks change nothing.
     */
    public function callback(Request $request, PaymentGateway $gateway, PaymentService $payments): RedirectResponse|JsonResponse
    {
        try {
            [$payment, $outcome] = $payments->handleCallback($gateway->verifyCallback($request));
        } catch (InvalidCallbackException $e) {
            abort(400, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['reference' => $payment->reference, 'status' => $payment->status->value, 'outcome' => $outcome]);
        }

        $message = match ($outcome) {
            PaymentService::CONFIRMED => ['success', 'Payment received. Your ticket is confirmed.'],
            PaymentService::CANCELLED => ['warning', 'Payment cancelled. Your seats are still held, so you can try again.'],
            PaymentService::FAILED => ['danger', 'The payment was declined and your seats were released.'],
            PaymentService::REFUNDED => ['danger', 'Your seat hold had expired and the seats were sold, so the payment is being refunded in full.'],
            default => ['info', 'This payment was already processed.'],
        };

        $route = $outcome === PaymentService::CANCELLED ? 'booking.checkout' : 'booking.show';

        return redirect()->route($route, $payment->booking)->with('payment_status', $message);
    }
}
