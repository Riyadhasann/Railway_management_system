<?php

namespace App\Services;

use App\Data\ScanOutcome;
use App\Enums\BookingStatus;
use App\Enums\ScanResult;
use App\Jobs\SendTicketEmail;
use App\Models\Booking;
use App\Models\ScheduleStop;
use App\Models\TicketScan;
use App\Models\TrainRun;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PNRs, signed QR tokens, the PDF e-ticket, and ticket checking:
 * validate() checks a scanned ticket for the TTE and records the scan.
 */
class TicketService
{
    /** No 0/O or 1/I, so a PNR can be read out over the phone without confusion. */
    public const string PNR_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const int PNR_LENGTH = 6;

    private const int MAX_ATTEMPTS = 10;

    /**
     * A PNR not used by any booking yet. bookings.pnr is unique, so a race
     * between two requests still can't produce a duplicate.
     */
    public function newPnr(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $pnr = $this->randomPnr();

            if (! Booking::where('pnr', $pnr)->exists()) {
                return $pnr;
            }
        }

        throw new RuntimeException('Could not generate a unique PNR.');
    }

    public function randomPnr(): string
    {
        $max = strlen(self::PNR_ALPHABET) - 1;
        $pnr = '';

        for ($i = 0; $i < self::PNR_LENGTH; $i++) {
            $pnr .= self::PNR_ALPHABET[random_int(0, $max)];
        }

        return $pnr;
    }

    /**
     * Ticket was confirmed: email the PDF once the transaction has committed.
     */
    public function issue(Booking $booking): void
    {
        if ($this->recipient($booking) !== null) {
            SendTicketEmail::dispatch($booking)->afterCommit();
        }
    }

    public function recipient(Booking $booking): ?string
    {
        return $booking->contact_email ?? $booking->user?->email;
    }

    /**
     * What the QR code holds: base64url(pnr|run_id|hmac_sha256(APP_KEY, pnr|run_id)).
     * Signed, so a ticket can't be forged from a PNR alone.
     */
    public function qrToken(Booking $booking): string
    {
        $payload = "{$booking->pnr}|{$booking->train_run_id}";

        return rtrim(strtr(base64_encode($payload.'|'.$this->sign($payload)), '+/', '-_'), '=');
    }

    /**
     * Read a scanned token. Null when it is malformed or its signature does not match.
     *
     * @return array{pnr: string, train_run_id: int}|null
     */
    public function parseToken(string $token): ?array
    {
        $decoded = base64_decode(strtr(trim($token), '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded);

        if (count($parts) !== 3 || ! ctype_digit($parts[1])) {
            return null;
        }

        [$pnr, $runId, $signature] = $parts;

        if (! hash_equals($this->sign("{$pnr}|{$runId}"), $signature)) {
            return null;
        }

        return ['pnr' => $pnr, 'train_run_id' => (int) $runId];
    }

    /**
     * Check a scanned ticket on the run the TTE is working, and record the scan.
     * A ticket is accepted once; later scans of it show as already used.
     */
    public function validate(string $token, TrainRun $run, User $tte): ScanOutcome
    {
        $parsed = $this->parseToken($token);

        if ($parsed === null) {
            return $this->record(ScanResult::Invalid, 'This is not a valid ticket code.', $run, $tte);
        }

        return DB::transaction(function () use ($parsed, $run, $tte) {
            // Lock the booking so two TTEs scanning the same ticket can't both accept it.
            /** @var Booking|null $booking */
            $booking = Booking::query()
                ->where('pnr', $parsed['pnr'])
                ->where('train_run_id', $parsed['train_run_id'])
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                return $this->record(ScanResult::Invalid, 'No booking matches this ticket.', $run, $tte, pnr: $parsed['pnr']);
            }

            if ($booking->train_run_id !== $run->id) {
                $ticketRun = $booking->trainRun;

                return $ticketRun->train_id === $run->train_id
                    ? $this->record(ScanResult::WrongDate, "This ticket is for {$ticketRun->journey_date->format('D d M Y')}, not today's run.", $run, $tte, $booking)
                    : $this->record(ScanResult::Invalid, "This ticket is for train {$ticketRun->train->label()}.", $run, $tte, $booking);
            }

            if ($booking->status === BookingStatus::Cancelled) {
                return $this->record(ScanResult::Cancelled, 'This booking was cancelled.', $run, $tte, $booking);
            }

            if (! $booking->hasTicket()) {
                return $this->record(ScanResult::Invalid, "This booking is {$booking->status->getLabel()}, not a paid ticket.", $run, $tte, $booking);
            }

            $first = $booking->ticketScans()->where('result', ScanResult::Valid->value)->oldest('scanned_at')->first();

            if ($first !== null) {
                return $this->record(ScanResult::AlreadyUsed, "Already checked at {$first->scanned_at->format('H:i')} by {$first->tte->name}.", $run, $tte, $booking, $first);
            }

            return $this->record(ScanResult::Valid, 'Valid ticket.', $run, $tte, $booking);
        });
    }

    /**
     * PNG QR code of the signed token, as a data URI (dompdf and <img> both read it).
     */
    public function qrDataUri(Booking $booking): string
    {
        $qr = new QrCode(data: $this->qrToken($booking), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 240, margin: 8);

        return (new PngWriter)->write($qr)->getDataUri();
    }

    /**
     * The printable e-ticket.
     */
    public function pdf(Booking $booking): DomPdf
    {
        $booking->loadMissing(['trainRun.train.scheduleStops.routeStop.station', 'passengers.seatBooking.seat', 'passengers.concession', 'payments']);
        $run = $booking->trainRun;
        $stops = $run->train->scheduleStops->keyBy(fn (ScheduleStop $s) => $s->routeStop->sequence);
        $from = $stops->get($booking->from_seq);
        $to = $stops->get($booking->to_seq);

        return Pdf::loadView('tickets.pdf', [
            'booking' => $booking,
            'run' => $run,
            'from' => $from,
            'to' => $to,
            'departure' => $from?->departureOn($run->journey_date),
            'arrival' => $to?->arrivalOn($run->journey_date),
            'payment' => $booking->paidPayment(),
            'qr' => $this->qrDataUri($booking),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);
    }

    public function pdfFilename(Booking $booking): string
    {
        return "ticket-{$booking->pnr}.pdf";
    }

    private function record(ScanResult $result, string $message, TrainRun $run, User $tte, ?Booking $booking = null, ?TicketScan $first = null, ?string $pnr = null): ScanOutcome
    {
        $scan = TicketScan::create([
            'booking_id' => $booking?->id,
            'train_run_id' => $run->id,
            'tte_id' => $tte->id,
            'result' => $result,
            'pnr' => $booking->pnr ?? $pnr,
            'scanned_at' => now(),
        ]);

        return new ScanOutcome($result, $message, $scan, $booking, $first);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
