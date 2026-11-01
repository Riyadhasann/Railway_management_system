<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RunStatus: string implements HasLabel
{
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Arrived = 'arrived';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
