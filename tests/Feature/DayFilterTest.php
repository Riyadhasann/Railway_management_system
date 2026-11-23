<?php

use App\Support\Day;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('day_probe', function (Blueprint $table) {
        $table->id();
        $table->date('day')->nullable();
        $table->timestamp('at')->nullable();
    });

    // Midnight, the last second of a day and the next midnight, around Nov 5.
    foreach (['2026-11-04 23:59:59', '2026-11-05 00:00:00', '2026-11-05 12:30:00', '2026-11-05 23:59:59', '2026-11-06 00:00:00', '2026-11-07 08:00:00'] as $at) {
        DB::table('day_probe')->insert(['day' => substr($at, 0, 10), 'at' => $at]);
    }

    // A date stored with a time part, as SQLite keeps Eloquent "date" casts.
    DB::table('day_probe')->insert(['day' => '2026-11-05 00:00:00', 'at' => null]);
});

afterEach(fn () => Schema::dropIfExists('day_probe'));

/**
 * The Day pattern for each whereDate() operator (see App\Support\Day).
 */
function dayFilter(Builder $q, string $column, string $operator, CarbonImmutable|string $value): Builder
{
    return match ($operator) {
        '=' => $q->where($column, '>=', Day::start($value))->where($column, '<', Day::after($value)),
        '>=' => $q->where($column, '>=', Day::start($value)),
        '>' => $q->where($column, '>=', Day::after($value)),
        '<=' => $q->where($column, '<', Day::after($value)),
        '<' => $q->where($column, '<', Day::start($value)),
    };
}

it('finds the same rows as whereDate, on date and timestamp columns', function (string $column, string $operator, CarbonImmutable|string $value) {
    $ids = fn (Builder $q) => $q->orderBy('id')->pluck('id')->all();

    $expected = $ids(DB::table('day_probe')->whereDate($column, $operator, $value));

    expect($expected)->not->toBeEmpty()
        ->and($ids(dayFilter(DB::table('day_probe'), $column, $operator, $value)))->toBe($expected);
})->with(['day', 'at'])->with(['=', '>=', '>', '<=', '<'])->with([
    'string' => '2026-11-05',
    'carbon late in the day' => CarbonImmutable::parse('2026-11-05 23:45'),
]);

it('gives the day and the next day as plain dates', function () {
    expect(Day::start('2026-12-31 22:10'))->toBe('2026-12-31')
        ->and(Day::after('2026-12-31'))->toBe('2027-01-01')
        ->and(Day::after(CarbonImmutable::parse('2026-02-28 23:59')))->toBe('2026-03-01')
        ->and(fn () => Day::start(' '))->toThrow(InvalidArgumentException::class);
});

it('puts no function on the column, so MySQL can use its index', function () {
    $sql = dayFilter(DB::table('day_probe'), 'at', '=', '2026-11-05')->toSql();

    expect(strtolower($sql))->not->toContain('date(')->not->toContain('strftime');
});
