<?php

namespace App\Services;

use App\Models\Booking;
use RuntimeException;

/**
 * PNRs now; ticket issue (PDF + signed QR) and validation come with payments and checking.
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
}
