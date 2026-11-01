<?php

use App\Services\TimetableValidator;

function row(int $seq, ?string $arr, ?string $dep, int $offset = 0): array
{
    return ['sequence' => $seq, 'arrival_time' => $arr, 'departure_time' => $dep, 'day_offset' => $offset];
}

describe('routes', function () {
    it('accepts a valid route', function () {
        expect((new TimetableValidator)->routeErrors([
            ['station_id' => 1, 'distance_km' => 0],
            ['station_id' => 2, 'distance_km' => 40],
            ['station_id' => 3, 'distance_km' => 95],
        ]))->toBe([]);
    });

    it('rejects bad routes', function (array $stops, string $message) {
        expect((new TimetableValidator)->routeErrors($stops))->toContain($message);
    })->with([
        'one station' => [[['station_id' => 1, 'distance_km' => 0]], 'A route needs at least two stations.'],
        'repeated station' => [[['station_id' => 1, 'distance_km' => 0], ['station_id' => 1, 'distance_km' => 10]], 'A station can appear only once on a route.'],
        'origin not at 0' => [[['station_id' => 1, 'distance_km' => 5], ['station_id' => 2, 'distance_km' => 10]], 'The first station must be at distance 0 km.'],
        'distance goes back' => [[['station_id' => 1, 'distance_km' => 0], ['station_id' => 2, 'distance_km' => 50], ['station_id' => 3, 'distance_km' => 40]], 'Distances must increase along the route (check stop 3).'],
    ]);
});

describe('timetables', function () {
    it('accepts a same-day timetable', function () {
        expect((new TimetableValidator)->scheduleErrors([
            row(1, null, '07:00'),
            row(2, '08:00', '08:05'),
            row(3, '09:30', null),
        ]))->toBe([]);
    });

    it('accepts an overnight timetable that uses day offsets', function () {
        expect((new TimetableValidator)->scheduleErrors([
            row(1, null, '23:30'),
            row(2, '01:40', '01:43', 1),
            row(3, '05:50', null, 1),
        ]))->toBe([]);
    });

    it('rejects an overnight timetable without day offsets', function () {
        expect((new TimetableValidator)->scheduleErrors([
            row(1, null, '23:30'),
            row(2, '01:40', '01:43'),
            row(3, '05:50', null),
        ]))->toContain('Stop 2 arrives before the train leaves the previous stop (set the day offset for overnight trains).');
    });

    it('rejects bad timetables', function (array $rows, string $message) {
        expect((new TimetableValidator)->scheduleErrors($rows))->toContain($message);
    })->with([
        'single stop' => [[row(1, null, '07:00')], 'A timetable needs at least two stops.'],
        'no origin departure' => [[row(1, null, null), row(2, '08:00', null)], 'The first stop needs a departure time.'],
        'no terminus arrival' => [[row(1, null, '07:00'), row(2, null, null)], 'The last stop needs an arrival time.'],
        'middle stop missing time' => [[row(1, null, '07:00'), row(2, '08:00', null), row(3, '09:00', null)], 'Stop 2 needs both arrival and departure times.'],
        'departs before arriving' => [[row(1, null, '07:00'), row(2, '08:10', '08:00'), row(3, '09:00', null)], 'Stop 2 departs before it arrives.'],
        'out of route order' => [[row(2, null, '07:00'), row(1, '08:00', null)], 'Stop 2 is out of route order.'],
    ]);
});
