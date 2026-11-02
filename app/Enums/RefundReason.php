<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RefundReason: string implements HasLabel
{
    /** The passenger cancelled online. */
    case PassengerCancelled = 'passenger_cancelled';
    /** Cancelled at the station counter. */
    case CounterCancelled = 'counter_cancelled';
    /** The railway cancelled the train run: always a full refund. */
    case TrainCancelled = 'train_cancelled';
    /** Paid after the hold ran out and the seats were sold to someone else. */
    case LatePayment = 'late_payment';
    /** A second successful payment for a booking that was already paid. */
    case DuplicatePayment = 'duplicate_payment';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Refunds that cancel the booking (the others return money for a payment that never bought a seat).
     */
    public function cancelsBooking(): bool
    {
        return in_array($this, [self::PassengerCancelled, self::CounterCancelled, self::TrainCancelled], true);
    }
}
