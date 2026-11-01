<?php

namespace App\Services;

/**
 * Pure validation rules for routes and timetables, shared by the
 * back-office forms and the seeders.
 */
class TimetableValidator
{
    /**
     * @param  list<array{station_id: mixed, distance_km: mixed}>  $stops  in route order
     * @return list<string>
     */
    public function routeErrors(array $stops): array
    {
        $errors = [];

        if (count($stops) < 2) {
            return ['A route needs at least two stations.'];
        }

        $stationIds = array_map(fn (array $s) => (string) $s['station_id'], $stops);
        if (count($stationIds) !== count(array_unique($stationIds))) {
            $errors[] = 'A station can appear only once on a route.';
        }

        if ((int) $stops[0]['distance_km'] !== 0) {
            $errors[] = 'The first station must be at distance 0 km.';
        }

        for ($i = 1; $i < count($stops); $i++) {
            if ((int) $stops[$i]['distance_km'] <= (int) $stops[$i - 1]['distance_km']) {
                $errors[] = 'Distances must increase along the route (check stop '.($i + 1).').';
                break;
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{sequence: int, arrival_time: ?string, departure_time: ?string, day_offset: mixed}>  $rows  in timetable order
     * @return list<string>
     */
    public function scheduleErrors(array $rows): array
    {
        if (count($rows) < 2) {
            return ['A timetable needs at least two stops.'];
        }

        $errors = [];
        $last = count($rows) - 1;
        $previousDeparture = null;

        foreach ($rows as $i => $row) {
            $n = $i + 1;

            if ($i > 0 && $row['sequence'] <= $rows[$i - 1]['sequence']) {
                $errors[] = "Stop {$n} is out of route order.";
            }

            $arrival = $this->minutes($row['arrival_time'], $row['day_offset']);
            $departure = $this->minutes($row['departure_time'], $row['day_offset']);

            if ($i === 0 && $departure === null) {
                $errors[] = 'The first stop needs a departure time.';
            }
            if ($i === $last && $arrival === null) {
                $errors[] = 'The last stop needs an arrival time.';
            }
            if ($i > 0 && $i < $last && ($arrival === null || $departure === null)) {
                $errors[] = "Stop {$n} needs both arrival and departure times.";
            }
            if ($arrival !== null && $departure !== null && $departure < $arrival) {
                $errors[] = "Stop {$n} departs before it arrives.";
            }
            if ($arrival !== null && $previousDeparture !== null && $arrival <= $previousDeparture) {
                $errors[] = "Stop {$n} arrives before the train leaves the previous stop (set the day offset for overnight trains).";
            }

            $previousDeparture = $departure ?? $previousDeparture;
        }

        return array_values(array_unique($errors));
    }

    /**
     * Minutes since the journey date's midnight.
     */
    private function minutes(?string $time, mixed $dayOffset): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $time));

        return ((int) $dayOffset) * 1440 + $h * 60 + $m;
    }
}
