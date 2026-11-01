<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a seat looks like on the seat map for one journey segment.
 */
enum SeatState: string implements HasLabel
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
