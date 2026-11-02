<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasLabel
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Badge colour in the passenger portal (Flux colour names).
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Confirmed, self::Completed => 'green',
            self::PendingPayment => 'amber',
            self::Cancelled => 'zinc',
            self::Failed, self::Expired => 'red',
        };
    }
}
