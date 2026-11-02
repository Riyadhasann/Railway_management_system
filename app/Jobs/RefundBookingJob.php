<?php

namespace App\Jobs;

use App\Enums\RefundReason;
use App\Models\Booking;
use App\Services\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cancel one booking and refund it in full (train run cancelled). Safe to retry.
 */
class RefundBookingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Booking $booking,
        public RefundReason $reason = RefundReason::TrainCancelled,
    ) {}

    public function handle(RefundService $refunds): void
    {
        $refunds->refundFull($this->booking, $this->reason);
    }
}
