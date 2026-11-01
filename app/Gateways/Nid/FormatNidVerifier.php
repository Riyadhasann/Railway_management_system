<?php

namespace App\Gateways\Nid;

use App\Contracts\NidVerifier;

/**
 * Prototype NID check: format only, no government lookup.
 * Bangladesh NIDs are 10 digits (smart card), 13 or 17 digits (older cards).
 */
class FormatNidVerifier implements NidVerifier
{
    public function verify(string $nid): bool
    {
        $nid = preg_replace('/[\s-]/', '', $nid) ?? '';

        return (bool) preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nid);
    }
}
