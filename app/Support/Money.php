<?php

namespace App\Support;

/**
 * Money is stored as integer poisha (1 taka = 100 poisha) and only formatted in views.
 */
final class Money
{
    public static function format(int $poisha): string
    {
        $decimals = $poisha % 100 === 0 ? 0 : 2;

        return '৳'.number_format($poisha / 100, $decimals);
    }

    public static function toPoisha(float|int|string $taka): int
    {
        return (int) round(((float) $taka) * 100);
    }
}
