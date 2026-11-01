<?php

use App\Enums\SeatClass;
use App\Models\CoachType;
use App\Services\SeatLayoutService;

it('fills a 2+2 chair coach leaving the aisle empty', function () {
    $cells = (new SeatLayoutService)->fill(2, 5, [3], berths: false);

    expect(array_keys($cells))->toBe(['1-1', '1-2', '1-4', '1-5', '2-1', '2-2', '2-4', '2-5'])
        ->and(array_column($cells, 'seat_no'))->toBe(['1', '2', '3', '4', '5', '6', '7', '8'])
        ->and(array_unique(array_column($cells, 'berth')))->toBe([null]);
});

it('alternates lower and upper berths in each block of a sleeper coach', function () {
    $cells = (new SeatLayoutService)->fill(1, 5, [3], berths: true);

    expect(array_column($cells, 'berth'))->toBe(['lower', 'upper', 'lower', 'upper']);
});

it('renumbers row by row, left to right', function () {
    $cells = (new SeatLayoutService)->renumber([
        '2-1' => ['seat_no' => '9', 'berth' => null, 'flags' => []],
        '1-4' => ['seat_no' => '3', 'berth' => null, 'flags' => []],
        '1-1' => ['seat_no' => '7', 'berth' => null, 'flags' => []],
    ]);

    expect($cells)->toMatchArray([
        '1-1' => ['seat_no' => '1', 'berth' => null, 'flags' => []],
        '1-4' => ['seat_no' => '2', 'berth' => null, 'flags' => []],
        '2-1' => ['seat_no' => '3', 'berth' => null, 'flags' => []],
    ]);
});

it('suggests the next free seat number', function () {
    $service = new SeatLayoutService;

    expect($service->nextSeatNo([]))->toBe('1')
        ->and($service->nextSeatNo(['1-1' => ['seat_no' => '4', 'berth' => null, 'flags' => []]]))->toBe('5');
});

it('reports layout errors', function () {
    $type = new CoachType(['layout_rows' => 2, 'layout_cols' => 2, 'seat_class' => SeatClass::AcChair]);
    $seat = fn (string $no, ?string $berth = null) => ['seat_no' => $no, 'berth' => $berth, 'flags' => []];

    $errors = (new SeatLayoutService)->errors($type, [
        '1-1' => $seat('1'),
        '1-2' => $seat('1'),
        '3-1' => $seat('2'),
        '2-2' => $seat('', 'middle'),
    ]);

    expect($errors)->toContain('Seat at row 3, column 1 is outside the 2 x 2 grid.')
        ->toContain('Every seat needs a number.')
        ->toContain('Duplicate seat numbers: 1.')
        ->toContain("Unknown berth 'middle'.")
        ->and((new SeatLayoutService)->errors($type, []))->toBe(['Add at least one seat.']);
});
