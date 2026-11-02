<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a booking was paid. Online methods go through the PaymentGateway; cash is taken at the counter.
 */
enum PaymentMethod: string implements HasColor, HasLabel
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Card = 'card';
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Card => 'Card',
            self::Cash => 'Cash',
        };
    }

    /**
     * @return string|array<int, string>
     */
    public function getColor(): string|array
    {
        return match ($this) {
            self::Bkash => Color::Pink,
            self::Nagad => Color::Orange,
            self::Card => 'info',
            self::Cash => 'success',
        };
    }

    public function isOnline(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * @return list<self>
     */
    public static function online(): array
    {
        return [self::Bkash, self::Nagad, self::Card];
    }
}
