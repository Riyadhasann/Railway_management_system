<?php

namespace App\Services;

use App\Models\CoachType;
use App\Models\Seat;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Logic behind the seat-layout editor. A layout is an array of cells keyed
 * "row-col" (1-based), each: ['seat_no' => string, 'berth' => ?string, 'flags' => list<string>].
 *
 * @phpstan-type Cell array{seat_no: string, berth: ?string, flags: list<string>}
 * @phpstan-type Layout array<string, Cell>
 */
class SeatLayoutService
{
    /**
     * Current layout of a coach type.
     *
     * @return Layout
     */
    public function load(CoachType $type): array
    {
        $cells = [];

        foreach ($type->seats()->get() as $seat) {
            $cells[self::key($seat->row, $seat->col)] = [
                'seat_no' => $seat->seat_no,
                'berth' => $seat->berth,
                'flags' => $seat->flags ?? [],
            ];
        }

        return $cells;
    }

    /**
     * Fill every column except the aisles with seats. Sleeper classes alternate
     * lower / upper berths across each block of columns.
     *
     * @param  list<int>  $aisleCols
     * @return Layout
     */
    public function fill(int $rows, int $cols, array $aisleCols, bool $berths): array
    {
        $cells = [];

        for ($r = 1; $r <= $rows; $r++) {
            $inBlock = 0;
            for ($c = 1; $c <= $cols; $c++) {
                if (in_array($c, $aisleCols, true)) {
                    $inBlock = 0;

                    continue;
                }

                $cells[self::key($r, $c)] = [
                    'seat_no' => '',
                    'berth' => $berths ? ($inBlock % 2 === 0 ? 'lower' : 'upper') : null,
                    'flags' => [],
                ];
                $inBlock++;
            }
        }

        return $this->renumber($cells);
    }

    /**
     * Number seats 1..N, row by row, left to right.
     *
     * @param  Layout  $cells
     * @return Layout
     */
    public function renumber(array $cells): array
    {
        uksort($cells, fn (string $a, string $b) => self::position($a) <=> self::position($b));

        $n = 1;
        foreach ($cells as $key => $cell) {
            $cells[$key]['seat_no'] = (string) $n++;
        }

        return $cells;
    }

    /**
     * Next free seat number for a newly painted seat.
     *
     * @param  Layout  $cells
     */
    public function nextSeatNo(array $cells): string
    {
        $numbers = array_map(fn (array $c) => (int) $c['seat_no'], $cells);

        return (string) (($numbers === [] ? 0 : max($numbers)) + 1);
    }

    /**
     * @param  Layout  $cells
     * @return list<string>
     */
    public function errors(CoachType $type, array $cells): array
    {
        $errors = [];

        if ($cells === []) {
            $errors[] = 'Add at least one seat.';
        }

        foreach (array_keys($cells) as $key) {
            [$r, $c] = self::split($key);
            if ($r < 1 || $c < 1 || $r > $type->layout_rows || $c > $type->layout_cols) {
                $errors[] = "Seat at row {$r}, column {$c} is outside the {$type->layout_rows} x {$type->layout_cols} grid.";
            }
        }

        $numbers = array_map(fn (array $c) => trim($c['seat_no']), $cells);
        if (in_array('', $numbers, true)) {
            $errors[] = 'Every seat needs a number.';
        }
        $duplicates = array_unique(array_diff_assoc($numbers, array_unique($numbers)));
        if ($duplicates !== []) {
            $errors[] = 'Duplicate seat numbers: '.implode(', ', $duplicates).'.';
        }

        foreach ($cells as $cell) {
            if ($cell['berth'] !== null && ! in_array($cell['berth'], Seat::BERTHS, true)) {
                $errors[] = "Unknown berth '{$cell['berth']}'.";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Persist a layout. Seats that have ever been booked or blocked cannot be removed.
     *
     * @param  Layout  $cells
     *
     * @throws DomainException
     */
    public function save(CoachType $type, array $cells): void
    {
        $errors = $this->errors($type, $cells);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        DB::transaction(function () use ($type, $cells) {
            $existing = $type->seats()->get()->keyBy(fn (Seat $s) => self::key($s->row, $s->col));

            $removed = $existing->reject(fn (Seat $s, string $key) => isset($cells[$key]));
            $inUse = $removed->filter(fn (Seat $s) => $s->seatBookings()->exists());
            if ($inUse->isNotEmpty()) {
                throw new DomainException('These seats have bookings or blocks and cannot be removed: '
                    .$inUse->pluck('seat_no')->implode(', ').'.');
            }
            Seat::whereKey($removed->modelKeys())->delete();

            // Park changed numbers first so swapping two seat numbers can't hit the unique index.
            foreach ($existing as $key => $seat) {
                if (isset($cells[$key]) && $cells[$key]['seat_no'] !== $seat->seat_no) {
                    $seat->update(['seat_no' => "~{$seat->id}"]);
                }
            }

            foreach ($cells as $key => $cell) {
                [$r, $c] = self::split($key);
                $attributes = [
                    'seat_no' => trim($cell['seat_no']),
                    'berth' => $cell['berth'],
                    'flags' => array_values(array_unique($cell['flags'])),
                ];

                if ($existing->has($key)) {
                    $existing[$key]->update($attributes);
                } else {
                    Seat::create($attributes + ['coach_type_id' => $type->id, 'row' => $r, 'col' => $c]);
                }
            }
        });
    }

    public static function key(int $row, int $col): string
    {
        return "{$row}-{$col}";
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function split(string $key): array
    {
        [$r, $c] = array_map('intval', explode('-', $key, 2) + [1 => '0']);

        return [$r, $c];
    }

    private static function position(string $key): int
    {
        [$r, $c] = self::split($key);

        return $r * 1000 + $c;
    }
}
