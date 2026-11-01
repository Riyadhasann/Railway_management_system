<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Passenger gender. Women-only seats can only be given to female passengers.
 */
enum Gender: string implements HasLabel
{
    case Female = 'female';
    case Male = 'male';
    case Other = 'other';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
