<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\TicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your e-ticket · PNR {$this->booking->pnr}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ticket', with: [
            'booking' => $this->booking->loadMissing('trainRun.train'),
            'url' => route('booking.show', $this->booking),
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $tickets = app(TicketService::class);

        return [
            Attachment::fromData(fn () => $tickets->pdf($this->booking)->output(), $tickets->pdfFilename($this->booking))
                ->withMime('application/pdf'),
        ];
    }
}
