<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a crew member does on a train.
 */
enum StaffDesignation: string implements HasLabel
{
    case Driver = 'driver';
    case AssistantDriver = 'assistant_driver';
    case Guard = 'guard';
    case Tte = 'tte';

    public function getLabel(): string
    {
        return match ($this) {
            self::Driver => 'Driver (loco master)',
            self::AssistantDriver => 'Assistant driver',
            self::Guard => 'Guard',
            self::Tte => 'TTE',
        };
    }

    /**
     * Drivers must hold a valid driving licence to be assigned.
     */
    public function needsLicence(): bool
    {
        return $this === self::Driver || $this === self::AssistantDriver;
    }
}
