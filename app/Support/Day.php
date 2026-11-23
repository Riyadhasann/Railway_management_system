<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Day boundaries for date filters that can use an index.
 *
 * whereDate('paid_at', '>=', $day) compiles to DATE(paid_at) >= ?, and a function on the
 * column stops MySQL using its index, so it reads every row. Compare the bare column with
 * the start of the day, or the start of the next day, instead:
 *
 *   on $day   ->  where(col, '>=', Day::start($day))->where(col, '<', Day::after($day))
 *   >= $day   ->  where(col, '>=', Day::start($day))
 *   <= $day   ->  where(col, '<',  Day::after($day))
 *   <  $day   ->  where(col, '<',  Day::start($day))
 *
 * Both return a plain 'Y-m-d', which compares correctly with DATE and DATETIME columns on
 * MySQL and with SQLite, where a date may be stored with or without a time part.
 */
final class Day
{
    /** The day itself, as 'Y-m-d'. */
    public static function start(DateTimeInterface|string $value): string
    {
        return self::parse($value)->toDateString();
    }

    /** The day after, as 'Y-m-d': the first moment that is no longer on $value's day. */
    public static function after(DateTimeInterface|string $value): string
    {
        return self::parse($value)->addDay()->toDateString();
    }

    private static function parse(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format('Y-m-d'));
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException('A date is needed.');
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
