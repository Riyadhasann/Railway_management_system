<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Initiated = 'initiated';
    case Success = 'success';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->ucfirst()->toString();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Initiated => 'gray',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Refunded, self::PartiallyRefunded => 'warning',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Initiated;
    }

    /**
     * Money was received (some of it may have been refunded since).
     *
     * @return list<string>
     */
    public static function captured(): array
    {
        return [self::Success->value, self::Refunded->value, self::PartiallyRefunded->value];
    }
}
