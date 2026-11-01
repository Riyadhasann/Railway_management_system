<?php

namespace App\Contracts;

interface NidVerifier
{
    /**
     * Whether the national ID number is acceptable for booking.
     */
    public function verify(string $nid): bool;
}
