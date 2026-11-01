<?php

namespace App\Exceptions;

use RuntimeException;

class SeatUnavailableException extends RuntimeException
{
    /**
     * @param  list<string>  $seats  e.g. ["KA-12", "KA-13"]
     */
    public static function for(array $seats): self
    {
        return new self('Seat(s) not available for this journey: '.implode(', ', $seats).'.');
    }
}
