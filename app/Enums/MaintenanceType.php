<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceType: string implements HasLabel
{
    case Scheduled = 'scheduled';
    case Breakdown = 'breakdown';
    case Overhaul = 'overhaul';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled service',
            self::Breakdown => 'Breakdown repair',
            self::Overhaul => 'Overhaul',
        };
    }
}
