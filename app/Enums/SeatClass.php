<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Travel class of a coach type. Fare rules are set per class.
 */
enum SeatClass: string implements HasLabel
{
    case AcBerth = 'ac_berth';
    case AcChair = 'ac_chair';
    case Snigdha = 'snigdha';
    case ShovanChair = 'shovan_chair';

    public function getLabel(): string
    {
        return match ($this) {
            self::AcBerth => 'AC berth',
            self::AcChair => 'AC chair',
            self::Snigdha => 'Snigdha',
            self::ShovanChair => 'Shovan chair',
        };
    }

    public function hasBerths(): bool
    {
        return $this === self::AcBerth;
    }
}
