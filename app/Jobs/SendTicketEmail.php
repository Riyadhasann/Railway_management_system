<?php

namespace App\Jobs;

use App\Mail\TicketMail;
use App\Models\Booking;
use App\Services\TicketService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Email the PDF e-ticket after a booking is confirmed.
 */
class SendTicketEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Booking $booking) {}

    public function handle(TicketService $tickets): void
    {
        $this->booking->refresh();
        $to = $tickets->recipient($this->booking);

        if ($to === null || ! $this->booking->hasTicket()) {
            return;
        }

        Mail::to($to)->send(new TicketMail($this->booking));
    }
}
