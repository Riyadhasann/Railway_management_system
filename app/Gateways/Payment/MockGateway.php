<?php

namespace App\Gateways\Payment;

use App\Contracts\PaymentGateway;
use App\Data\GatewayResult;
use App\Exceptions\InvalidCallbackException;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Prototype gateway: a bKash / Nagad / Card styled page with Success, Fail and Cancel.
 * The page's buttons post a signed callback, exactly as a real gateway would,
 * so PaymentService can't tell the difference. No real money moves.
 */
class MockGateway implements PaymentGateway
{
    /** Minutes the pay page link stays valid. */
    public const int LINK_MINUTES = 30;

    public function startCheckout(Payment $payment): string
    {
        return URL::temporarySignedRoute('payments.mock', now()->addMinutes(self::LINK_MINUTES), [
            'payment' => $payment->reference,
        ]);
    }

    /**
     * Fields the pay page posts for one outcome. The transaction id is fixed when the page
     * is rendered, so pressing the button twice sends the same callback twice.
     *
     * @return array{reference: string, outcome: string, txn_id: string, signature: string}
     */
    public function callbackFields(Payment $payment, string $outcome, string $txnId): array
    {
        return [
            'reference' => $payment->reference,
            'outcome' => $outcome,
            'txn_id' => $txnId,
            'signature' => $this->sign($payment->reference, $outcome, $txnId),
        ];
    }

    public function newTxnId(): string
    {
        return 'MOCK'.strtoupper(Str::random(12));
    }

    public function verifyCallback(Request $request): GatewayResult
    {
        $reference = (string) $request->input('reference', '');
        $outcome = (string) $request->input('outcome', '');
        $txnId = (string) $request->input('txn_id', '');
        $signature = (string) $request->input('signature', '');

        if ($reference === '' || $txnId === '' || ! in_array($outcome, [GatewayResult::SUCCESS, GatewayResult::FAILED, GatewayResult::CANCELLED], true)) {
            throw new InvalidCallbackException('The payment callback is incomplete.');
        }

        if (! hash_equals($this->sign($reference, $outcome, $txnId), $signature)) {
            throw new InvalidCallbackException('The payment callback signature is not valid.');
        }

        return new GatewayResult(
            outcome: $outcome,
            reference: $reference,
            txnId: $txnId,
            message: match ($outcome) {
                GatewayResult::FAILED => 'The payment was declined.',
                GatewayResult::CANCELLED => 'The payment was cancelled.',
                default => null,
            },
        );
    }

    public function refund(Payment $payment, int $amountPoisha): GatewayResult
    {
        return new GatewayResult(
            outcome: GatewayResult::SUCCESS,
            reference: $payment->reference,
            txnId: 'MOCKRF'.strtoupper(Str::random(10)),
        );
    }

    private function sign(string $reference, string $outcome, string $txnId): string
    {
        return hash_hmac('sha256', "mock-payment|{$reference}|{$outcome}|{$txnId}", (string) config('app.key'));
    }
}
