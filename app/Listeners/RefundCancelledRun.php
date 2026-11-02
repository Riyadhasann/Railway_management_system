<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\TrainRunCancelled;
use App\Jobs\RefundBookingJob;
use App\Models\Booking;
use App\Services\SeatInventoryService;

/**
 * Release unpaid holds on the cancelled run and queue a full refund for every confirmed booking.
 * A hold that is paid later is refunded by PaymentService (the run is cancelled, so it can't confirm).
 */
class RefundCancelledRun
{
    public function __construct(private readonly SeatInventoryService $inventory) {}

    public function handle(TrainRunCancelled $event): void
    {
        $bookings = Booking::query()
            ->where('train_run_id', $event->run->id)
            ->whereIn('status', [BookingStatus::PendingPayment->value, BookingStatus::Confirmed->value])
            ->with('trainRun')
            ->get();

        foreach ($bookings as $booking) {
            if ($booking->status === BookingStatus::PendingPayment) {
                $this->inventory->release($booking, BookingStatus::Cancelled);
            } else {
                RefundBookingJob::dispatch($booking);
            }
        }
    }
}
