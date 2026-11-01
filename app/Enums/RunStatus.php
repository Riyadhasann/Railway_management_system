<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RunStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Arrived = 'arrived';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Running => 'warning',
            self::Arrived => 'success',
            self::Cancelled => 'danger',
        };
    }
}
