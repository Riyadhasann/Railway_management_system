<?php

namespace App\Exceptions;

use App\Enums\SeatClass;
use RuntimeException;

class FareNotFoundException extends RuntimeException
{
    public static function for(SeatClass $class, int $km): self
    {
        return new self("No fare rule for {$class->getLabel()} at {$km} km.");
    }
}
