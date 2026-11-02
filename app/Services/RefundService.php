<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\RefundQuote;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Exceptions\BookingRefusedException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundRule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancellations and refunds. A refund is created inside the booking's transaction
 * (status pending) and sent to the gateway afterwards by process(), so a slow or
 * failing gateway never holds the train-run lock. Failed refunds can be retried.
 */
class RefundService
{
    public function __construct(
        private readonly SeatInventoryService $inventory,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Refund for cancelling now (or at $at), from the hours-before-departure rules:
     * the rule with the largest hours_before that still applies wins; no rule, no refund.
     */
    public function quote(Booking $booking, ?CarbonInterface $at = null): RefundQuote
    {
        $at ??= now();
        $departure = $this->inventory->boardingDeparture($booking->trainRun, $booking->from_seq);
        $hours = $departure === null ? 0.0 : max(0.0, $at->diffInSeconds($departure, false) / 3600);
        $payment = $booking->paidPayment();
        $paid = $payment === null ? 0 : $this->refundable($payment);

        $rule = $hours > 0
            ? RefundRule::query()->where('hours_before', '<=', $hours)->orderByDesc('hours_before')->first()
            : null;

        if ($rule === null || $paid === 0) {
            return new RefundQuote(round($hours, 2), $paid, 0, 0, 0, $rule);
        }

        $gross = intdiv($paid * $rule->percent, 100);
        $fee = min($rule->fee_poisha, $gross);

        return new RefundQuote(round($hours, 2), $paid, $rule->percent, $fee, $gross - $fee, $rule);
    }

    /**
     * Passenger or counter cancellation of a confirmed booking before departure.
     * Seats go back on sale; the refund follows the rules (none if no rule applies).
     *
     * @throws BookingRefusedException
     */
    public function cancel(Booking $booking, ?User $by = null, RefundReason $reason = RefundReason::PassengerCancelled): ?Refund
    {
        $refund = DB::transaction(function () use ($booking, $by, $reason) {
            $this->inventory->lock($booking->trainRun);
            $booking->refresh();

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new BookingRefusedException("Booking {$booking->pnr} is {$booking->status->getLabel()} and can not be cancelled.");
            }

            $departure = $this->inventory->boardingDeparture($booking->trainRun, $booking->from_seq);

            if ($departure === null || $departure->isPast()) {
                throw new BookingRefusedException('The train has already left, so this booking can no longer be cancelled.');
            }

            $quote = $this->quote($booking);
            $this->markCancelled($booking, $by);

            $payment = $booking->paidPayment();

            return $payment !== null && $quote->isRefundable()
                ? $this->createRefund($payment, $reason, $quote->amountPoisha, $quote->percent, $quote->feePoisha, $quote->hoursBefore, $by)
                : null;
        });

        return $refund === null ? null : $this->process($refund);
    }

    /**
     * The railway cancelled the train: cancel the booking and refund everything paid, no fee.
     * Safe to call again; a booking that is no longer confirmed is left alone.
     */
    public function refundFull(Booking $booking, RefundReason $reason = RefundReason::TrainCancelled): ?Refund
    {
        $refund = DB::transaction(function () use ($booking, $reason) {
            $this->inventory->lock($booking->trainRun);
            $booking->refresh();

            if ($booking->status !== BookingStatus::Confirmed) {
                return null;
            }

            $this->markCancelled($booking, null);
            $payment = $booking->paidPayment();
            $amount = $payment === null ? 0 : $this->refundable($payment);

            return $payment !== null && $amount > 0 ? $this->createRefund($payment, $reason, $amount, 100, 0, null, null) : null;
        });

        return $refund === null ? null : $this->process($refund);
    }

    /**
     * Return a whole payment that did not buy a seat (late or duplicate payment).
     * Call inside the payment's transaction, then process() the refund after it commits.
     */
    public function refundPayment(Payment $payment, RefundReason $reason): ?Refund
    {
        $amount = $this->refundable($payment);

        return $amount > 0 ? $this->createRefund($payment, $reason, $amount, 100, 0, null, null) : null;
    }

    /**
     * Send a pending (or failed) refund: through the gateway for online payments,
     * handed over in cash at the counter for cash payments.
     */
    public function process(Refund $refund): Refund
    {
        if ($refund->status === RefundStatus::Processed) {
            return $refund;
        }

        $payment = $refund->payment;

        try {
            $result = $payment->method === PaymentMethod::Cash
                ? null
                : $this->gateway->refund($payment, $refund->amount_poisha);
            $ok = $result === null || $result->succeeded();
            $txnId = $result?->txnId;
            $error = $ok ? null : ($result->message ?? 'The gateway refused the refund.');
        } catch (Throwable $e) {
            Log::error('Refund failed', ['refund' => $refund->id, 'error' => $e->getMessage()]);
            [$ok, $txnId, $error] = [false, null, $e->getMessage()];
        }

        return DB::transaction(function () use ($refund, $ok, $txnId, $error) {
            /** @var Refund $locked */
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RefundStatus::Processed) {
                return $locked;
            }

            if (! $ok) {
                $locked->update(['status' => RefundStatus::Failed, 'failure_reason' => $error]);

                return $locked;
            }

            $locked->update([
                'status' => RefundStatus::Processed,
                'txn_id' => $txnId,
                'failure_reason' => null,
                'processed_at' => now(),
            ]);

            /** @var Payment $payment */
            $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $refunded = $payment->refunded_poisha + $locked->amount_poisha;
            $payment->update([
                'refunded_poisha' => $refunded,
                'status' => $refunded >= $payment->amount_poisha ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ]);

            return $locked;
        });
    }

    /**
     * Paid and not yet refunded, less refunds still waiting to be sent.
     */
    private function refundable(Payment $payment): int
    {
        $reserved = (int) $payment->refunds()
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value])
            ->sum('amount_poisha');

        return max(0, $payment->refundableAmount() - $reserved);
    }

    private function createRefund(Payment $payment, RefundReason $reason, int $amount, int $percent, int $fee, ?float $hoursBefore, ?User $by): Refund
    {
        return Refund::create([
            'booking_id' => $payment->booking_id,
            'payment_id' => $payment->id,
            'reason' => $reason,
            'amount_poisha' => $amount,
            'fee_poisha' => $fee,
            'percent' => $percent,
            'hours_before' => $hoursBefore,
            'status' => RefundStatus::Pending,
            'requested_by' => $by?->id,
        ]);
    }

    private function markCancelled(Booking $booking, ?User $by): void
    {
        $this->inventory->cancelSeats($booking);
        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by?->id,
        ]);
    }
}
