<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The hold ran out and someone else has taken at least one of its seats since.
 */
class HoldExpiredException extends RuntimeException
{
    public static function for(string $pnr): self
    {
        return new self("The seat hold for {$pnr} has expired and the seats have been taken by someone else.");
    }
}
