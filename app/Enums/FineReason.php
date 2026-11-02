<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FineReason: string implements HasLabel
{
    case NoTicket = 'no_ticket';
    case BeyondDestination = 'beyond_destination';
    case WrongClass = 'wrong_class';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoTicket => 'No ticket',
            self::BeyondDestination => 'Travelling beyond destination',
            self::WrongClass => 'Wrong class',
            self::Other => 'Other',
        };
    }
}
