<?php

namespace App\Mail;

use App\Models\Locomotive;
use App\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExpiryWarningsMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Staff>  $licences
     * @param  Collection<int, Locomotive>  $locos
     */
    public function __construct(public Collection $licences, public Collection $locos) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Crew and loco warnings: {$this->licences->count()} licences, {$this->locos->count()} locos");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.expiry-warnings', with: [
            'url' => url('/admin/crew-assignments'),
        ]);
    }
}
