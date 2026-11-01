<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a booking was sold. Counter-quota seats can only be sold at the counter.
 */
enum Channel: string implements HasLabel
{
    case Online = 'online';
    case Counter = 'counter';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
