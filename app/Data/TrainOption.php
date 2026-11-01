<?php

namespace App\Data;

use App\Enums\SeatClass;
use App\Models\TrainRun;
use Carbon\CarbonImmutable;

/**
 * One train in the passenger search results for a station pair and travel date.
 */
final readonly class TrainOption
{
    /**
     * @param  list<array{class: SeatClass, available: int, fare: int|null}>  $classes  fare in poisha, null when no fare rule
     */
    public function __construct(
        public TrainRun $run,
        public int $fromSeq,
        public int $toSeq,
        public CarbonImmutable $departure,
        public CarbonImmutable $arrival,
        public int $distanceKm,
        public array $classes,
    ) {}

    public function isCancelled(): bool
    {
        return $this->run->isCancelled();
    }

    public function hasDeparted(): bool
    {
        return $this->departure->isPast();
    }

    public function isBookable(): bool
    {
        return ! $this->isCancelled() && ! $this->hasDeparted() && $this->availableSeats() > 0;
    }

    public function availableSeats(): int
    {
        return array_sum(array_column($this->classes, 'available'));
    }

    public function durationLabel(): string
    {
        $minutes = (int) $this->departure->diffInMinutes($this->arrival);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
