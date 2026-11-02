<?php

namespace App\Data;

use App\Enums\ScanResult;
use App\Models\Booking;
use App\Models\TicketScan;

/**
 * What the TTE sees after scanning a ticket.
 */
final readonly class ScanOutcome
{
    public function __construct(
        public ScanResult $result,
        public string $message,
        public TicketScan $scan,
        public ?Booking $booking = null,
        /** For already_used: when the ticket was first accepted. */
        public ?TicketScan $firstScan = null,
    ) {}

    public function isValid(): bool
    {
        return $this->result === ScanResult::Valid;
    }
}
