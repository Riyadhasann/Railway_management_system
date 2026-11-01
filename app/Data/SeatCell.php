<?php

namespace App\Data;

use App\Enums\SeatState;

/**
 * One seat on the seat map for a given journey segment.
 */
final readonly class SeatCell
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public int $seatId,
        public string $seatNo,
        public int $row,
        public int $col,
        public ?string $berth,
        public array $flags,
        public SeatState $state,
    ) {}

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
