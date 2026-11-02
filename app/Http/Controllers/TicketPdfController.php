<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Booking;
use App\Services\TicketService;
use Illuminate\Http\Response;

class TicketPdfController extends Controller
{
    /**
     * Download the PDF e-ticket: the passenger who owns the booking, or counter / finance staff.
     */
    public function __invoke(Booking $booking, TicketService $tickets): Response
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && ($booking->user_id === $user->id
                || $user->can(Permission::CounterBooking->value)
                || $user->can(Permission::ViewPayments->value)),
            403,
        );
        abort_unless($booking->hasTicket(), 404, 'This booking has no valid ticket.');

        return $tickets->pdf($booking)->download($tickets->pdfFilename($booking));
    }
}
