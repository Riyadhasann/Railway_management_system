<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Data\GatewayResult;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\InvalidCallbackException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Online checkout and the gateway callback. The callback takes the same train-run lock
 * as every other inventory write, is idempotent (a payment that is already final is left
 * alone, and payments.txn_id is unique), and applies the late-payment rule: money for a
 * booking whose seats were sold after the hold expired is refunded, never double-sold.
 */
class PaymentService
{
    /** What happened when a callback was applied. */
    public const string CONFIRMED = 'confirmed';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    /** Paid, but the seats were gone (or the booking was already paid): the money goes back. */
    public const string REFUNDED = 'refunded';

    /** The payment was already final; nothing changed. */
    public const string IGNORED = 'ignored';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SeatInventoryService $inventory,
        private readonly RefundService $refunds,
        private readonly TicketService $tickets,
    ) {}

    /**
     * Start an online payment for a held booking. Returns the gateway URL.
     *
     * @throws BookingRefusedException
     */
    public function start(Booking $booking, PaymentMethod $method): string
    {
        if (! $method->isOnline()) {
            throw new InvalidArgumentException('Cash is taken at the counter, not through the gateway.');
        }

        $booking->refresh();

        if (! $booking->isHeld()) {
            throw new BookingRefusedException('Your seat hold has expired. Please choose your seats again.');
        }

        if (! $booking->passengers()->exists()) {
            throw new BookingRefusedException('Save the passenger details before paying.');
        }

        $payment = $booking->payments()->create([
            'method' => $method,
            'reference' => $this->newReference(),
            'amount_poisha' => $booking->total_poisha,
            'status' => PaymentStatus::Initiated,
        ]);

        return $this->gateway->startCheckout($payment);
    }

    /**
     * Apply a verified gateway callback.
     *
     * @return array{0: Payment, 1: string} the payment and one of the outcome constants
     *
     * @throws InvalidCallbackException if the reference is unknown or the amount is wrong
     */
    public function handleCallback(GatewayResult $result): array
    {
        $payment = Payment::query()->where('reference', $result->reference)->with('booking.trainRun')->first()
            ?? throw new InvalidCallbackException("Unknown payment reference {$result->reference}.");

        /** @var array{0: string, 1: Refund|null} $applied */
        $applied = DB::transaction(function () use ($payment, $result) {
            $this->inventory->lock($payment->booking->trainRun);

            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isFinal()) {
                return [self::IGNORED, null];
            }

            if (! $result->succeeded()) {
                return [$this->applyFailure($locked, $result), null];
            }

            return $this->applySuccess($locked, $result);
        });

        [$outcome, $refund] = $applied;

        if ($refund !== null) {
            $this->refunds->process($refund);
        }

        return [$payment->refresh(), $outcome];
    }

    /**
     * Counter sale: the clerk took cash for a confirmed booking.
     */
    public function recordCash(Booking $booking, User $clerk): Payment
    {
        return $booking->payments()->create([
            'method' => PaymentMethod::Cash,
            'reference' => $this->newReference('CSH'),
            'amount_poisha' => $booking->total_poisha,
            'status' => PaymentStatus::Success,
            'paid_at' => now(),
            'received_by' => $clerk->id,
        ]);
    }

    private function applyFailure(Payment $payment, GatewayResult $result): string
    {
        $cancelled = $result->outcome === GatewayResult::CANCELLED;

        $payment->update([
            'status' => PaymentStatus::Failed,
            'txn_id' => $this->freeTxnId($result->txnId),
            'failure_reason' => $result->message ?? ($cancelled ? 'Cancelled by the passenger.' : 'Declined.'),
        ]);

        // Cancelling on the gateway page keeps the hold, so another method can be tried.
        // A declined payment fails the booking and puts its seats back on sale.
        if (! $cancelled) {
            $this->inventory->release($payment->booking, BookingStatus::Failed);
        }

        return $cancelled ? self::CANCELLED : self::FAILED;
    }

    /**
     * @return array{0: string, 1: Refund|null}
     */
    private function applySuccess(Payment $payment, GatewayResult $result): array
    {
        if ($result->txnId !== null && Payment::where('txn_id', $result->txnId)->exists()) {
            return [self::IGNORED, null]; // the same gateway transaction was already applied to another payment
        }

        try {
            $payment->update([
                'status' => PaymentStatus::Success,
                'txn_id' => $result->txnId,
                'paid_at' => now(),
                'failure_reason' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return [self::IGNORED, null];
        }

        $booking = $payment->booking;
        $booking->refresh();

        // Another payment already bought this booking.
        if ($booking->status === BookingStatus::Confirmed) {
            $payment->update(['failure_reason' => 'Booking was already paid.']);

            return [self::REFUNDED, $this->refunds->refundPayment($payment, RefundReason::DuplicatePayment)];
        }

        try {
            $this->inventory->confirm($booking);
        } catch (HoldExpiredException|BookingRefusedException $e) {
            $payment->update(['failure_reason' => $e->getMessage()]);

            return [self::REFUNDED, $this->refunds->refundPayment($payment, RefundReason::LatePayment)];
        }

        $this->tickets->issue($booking->refresh());

        return [self::CONFIRMED, null];
    }

    /**
     * A declined callback may reuse a txn id; only store it if nobody has it yet.
     */
    private function freeTxnId(?string $txnId): ?string
    {
        return $txnId !== null && ! Payment::where('txn_id', $txnId)->exists() ? $txnId : null;
    }

    private function newReference(string $prefix = 'PAY'): string
    {
        return $prefix.strtoupper((string) Str::ulid());
    }
}
