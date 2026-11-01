<?php

namespace App\Data;

/**
 * Base fare for a set of seats on one journey segment (before concessions).
 */
final readonly class FareQuote
{
    /**
     * @param  array<string, int>  $seatFares  "coachNo|seatId" => fare in poisha
     */
    public function __construct(
        public int $distanceKm,
        public array $seatFares,
    ) {}

    public function total(): int
    {
        return array_sum($this->seatFares);
    }

    public function forSeat(string $coachNo, int $seatId): int
    {
        return $this->seatFares["{$coachNo}|{$seatId}"]
            ?? throw new \InvalidArgumentException("Seat {$coachNo}/{$seatId} is not in this quote.");
    }
}
