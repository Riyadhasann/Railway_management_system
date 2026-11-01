<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ScanResult: string implements HasLabel
{
    case Valid = 'valid';
    case AlreadyUsed = 'already_used';
    case Cancelled = 'cancelled';
    case WrongDate = 'wrong_date';
    case Invalid = 'invalid';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->ucfirst()->toString();
    }
}
