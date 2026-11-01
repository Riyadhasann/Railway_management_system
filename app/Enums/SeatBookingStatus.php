<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SeatBookingStatus: string implements HasLabel
{
    case Held = 'held';
    case Booked = 'booked';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Statuses that always make a seat segment unavailable.
     * (Held also counts while hold_until is in the future.)
     *
     * @return list<string>
     */
    public static function alwaysTaken(): array
    {
        return [self::Booked->value, self::Blocked->value];
    }
}
