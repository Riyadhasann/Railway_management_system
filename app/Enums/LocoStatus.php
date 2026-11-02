<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LocoStatus: string implements HasColor, HasLabel
{
    case Available = 'available';
    case UnderRepair = 'under_repair';
    case Retired = 'retired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::UnderRepair => 'Under repair',
            self::Retired => 'Retired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::UnderRepair => 'warning',
            self::Retired => 'gray',
        };
    }
}
