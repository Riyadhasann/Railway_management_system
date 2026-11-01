<?php

namespace App\Data;

use App\Enums\SeatClass;
use App\Enums\SeatState;

/**
 * One coach of a train run, with every seat's state for a journey segment.
 */
final readonly class CoachMap
{
    /**
     * @param  list<SeatCell>  $seats
     */
    public function __construct(
        public string $coachNo,
        public string $coachTypeName,
        public SeatClass $seatClass,
        public int $rows,
        public int $cols,
        public array $seats,
    ) {}

    public function count(SeatState $state): int
    {
        return count(array_filter($this->seats, fn (SeatCell $s) => $s->state === $state));
    }

    public function availableCount(): int
    {
        return $this->count(SeatState::Available);
    }

    public function at(int $row, int $col): ?SeatCell
    {
        foreach ($this->seats as $seat) {
            if ($seat->row === $row && $seat->col === $col) {
                return $seat;
            }
        }

        return null;
    }
}
