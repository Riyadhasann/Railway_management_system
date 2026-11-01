<?php

namespace Database\Seeders;

use App\Models\RouteStop;
use App\Models\ScheduleStop;
use App\Models\Station;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Services\TimetableValidator;
use App\Services\TrainRunService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo network for testers: 14 stations, 2 lines (4 directional routes),
 * 6 trains including an overnight one, and runs for the next N days.
 * Sample data for testing only. Safe to re-run: existing records are kept.
 */
class DemoNetworkSeeder extends Seeder
{
    /** code => [name, division] */
    private const array STATIONS = [
        'DHK' => ['Dhaka (Kamalapur)', 'Dhaka'],
        'BBD' => ['Biman Bandar', 'Dhaka'],
        'NSD' => ['Narsingdi', 'Dhaka'],
        'BRB' => ['Bhairab Bazar', 'Dhaka'],
        'BBA' => ['Brahmanbaria', 'Chattogram'],
        'AKA' => ['Akhaura', 'Chattogram'],
        'CML' => ['Cumilla', 'Chattogram'],
        'FEN' => ['Feni', 'Chattogram'],
        'CTG' => ['Chattogram', 'Chattogram'],
        'JDP' => ['Joydebpur', 'Dhaka'],
        'TGL' => ['Tangail', 'Dhaka'],
        'ISD' => ['Ishwardi', 'Rajshahi'],
        'APR' => ['Abdulpur', 'Rajshahi'],
        'RJS' => ['Rajshahi', 'Rajshahi'],
    ];

    /** Lines in the "down" direction: code => [name, [station => km]] */
    private const array LINES = [
        'DHK-CTG' => ['Dhaka to Chattogram', [
            'DHK' => 0, 'BBD' => 20, 'NSD' => 60, 'BRB' => 85, 'BBA' => 115,
            'AKA' => 130, 'CML' => 175, 'FEN' => 230, 'CTG' => 320,
        ]],
        'DHK-RJS' => ['Dhaka to Rajshahi', [
            'DHK' => 0, 'BBD' => 20, 'JDP' => 35, 'TGL' => 100,
            'ISD' => 225, 'APR' => 245, 'RJS' => 260,
        ]],
    ];

    /**
     * number => [name, route code, running days, [[station, arrives, departs, day offset], ...]]
     */
    private const array TRAINS = [
        '701' => ['Shapla Express', 'DHK-CTG', [1, 2, 3, 4, 5, 6, 7], [
            ['DHK', null, '07:00', 0], ['BBD', '07:25', '07:28', 0], ['BRB', '08:45', '08:48', 0],
            ['CML', '10:10', '10:13', 0], ['CTG', '12:20', null, 0],
        ]],
        '702' => ['Shapla Express', 'CTG-DHK', [1, 2, 3, 4, 5, 6, 7], [
            ['CTG', null, '15:00', 0], ['CML', '17:05', '17:08', 0], ['BRB', '18:30', '18:33', 0],
            ['BBD', '19:50', '19:53', 0], ['DHK', '20:20', null, 0],
        ]],
        '721' => ['Bakul Intercity', 'DHK-CTG', [1, 2, 3, 4, 5, 6], [
            ['DHK', null, '07:45', 0], ['BBD', '08:10', '08:13', 0], ['NSD', '08:55', '08:58', 0],
            ['BRB', '09:25', '09:28', 0], ['BBA', '10:00', '10:03', 0], ['AKA', '10:20', '10:23', 0],
            ['CML', '11:10', '11:13', 0], ['FEN', '12:10', '12:13', 0], ['CTG', '13:50', null, 0],
        ]],
        '741' => ['Night Star', 'DHK-CTG', [1, 2, 3, 4, 5, 6, 7], [
            ['DHK', null, '23:30', 0], ['BBD', '23:55', '23:58', 0], ['BBA', '01:40', '01:43', 1],
            ['CML', '02:50', '02:53', 1], ['CTG', '05:50', null, 1],
        ]],
        '753' => ['Kadam Express', 'DHK-RJS', [1, 2, 3, 4, 5, 6], [
            ['DHK', null, '14:45', 0], ['BBD', '15:10', '15:13', 0], ['JDP', '15:35', '15:38', 0],
            ['TGL', '16:45', '16:48', 0], ['ISD', '19:20', '19:23', 0], ['APR', '19:50', '19:53', 0],
            ['RJS', '20:30', null, 0],
        ]],
        '754' => ['Kadam Express', 'RJS-DHK', [1, 2, 3, 4, 5, 6], [
            ['RJS', null, '07:40', 0], ['APR', '08:15', '08:18', 0], ['ISD', '08:45', '08:48', 0],
            ['TGL', '11:20', '11:23', 0], ['JDP', '12:30', '12:33', 0], ['BBD', '12:55', '12:58', 0],
            ['DHK', '13:30', null, 0],
        ]],
    ];

    public function run(TimetableValidator $validator, TrainRunService $runs): void
    {
        DB::transaction(function () use ($validator) {
            $stations = $this->seedStations();
            $this->seedRoutes($stations, $validator);
            $this->seedTrains($stations, $validator);
        });

        $created = $runs->generate();

        $this->command?->info(sprintf(
            'Demo network: %d stations, %d routes, %d trains, %d new runs.',
            Station::count(), TrainRoute::count(), Train::count(), $created,
        ));
    }

    /**
     * @return array<string, int> station code => id
     */
    private function seedStations(): array
    {
        $ids = [];

        foreach (self::STATIONS as $code => [$name, $division]) {
            $ids[$code] = Station::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'division' => $division, 'is_active' => true],
            )->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $stations
     */
    private function seedRoutes(array $stations, TimetableValidator $validator): void
    {
        foreach (self::LINES as $code => [$name, $stops]) {
            $this->seedRoute($code, $name, $stops, $stations, $validator);

            // The "up" direction: same stations reversed, distances measured from the other end.
            $length = max($stops);
            $reversed = array_map(fn (int $km) => $length - $km, array_reverse($stops, true));
            [$from, $to] = explode('-', $code);
            [$fromName, $toName] = explode(' to ', $name);
            $this->seedRoute("{$to}-{$from}", "{$toName} to {$fromName}", $reversed, $stations, $validator);
        }
    }

    /**
     * @param  array<string, int>  $stops  station code => km
     * @param  array<string, int>  $stations
     */
    private function seedRoute(string $code, string $name, array $stops, array $stations, TimetableValidator $validator): void
    {
        $route = TrainRoute::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);

        if ($route->stops()->exists()) {
            return;
        }

        $rows = [];
        foreach ($stops as $station => $km) {
            $rows[] = ['station_id' => $stations[$station], 'distance_km' => $km];
        }

        $this->assertValid("Route {$code}", $validator->routeErrors($rows));

        foreach ($rows as $i => $row) {
            RouteStop::create($row + ['train_route_id' => $route->id, 'sequence' => $i + 1]);
        }
    }

    /**
     * @param  array<string, int>  $stations
     */
    private function seedTrains(array $stations, TimetableValidator $validator): void
    {
        foreach (self::TRAINS as $number => [$name, $routeCode, $days, $timetable]) {
            $route = TrainRoute::where('code', $routeCode)->firstOrFail();

            $train = Train::firstOrCreate(
                ['number' => (string) $number],
                ['name' => $name, 'train_route_id' => $route->id, 'running_days' => $days, 'is_active' => true],
            );

            if ($train->scheduleStops()->exists()) {
                continue;
            }

            $routeStops = $route->stops()->get()->keyBy('station_id');
            $rows = [];

            foreach ($timetable as [$station, $arrives, $departs, $offset]) {
                $routeStop = $routeStops[$stations[$station]];
                $rows[] = [
                    'route_stop_id' => $routeStop->id,
                    'sequence' => $routeStop->sequence,
                    'arrival_time' => $arrives,
                    'departure_time' => $departs,
                    'day_offset' => $offset,
                ];
            }

            $this->assertValid("Train {$number}", $validator->scheduleErrors($rows));

            foreach ($rows as $row) {
                ScheduleStop::create([
                    'train_id' => $train->id,
                    'route_stop_id' => $row['route_stop_id'],
                    'arrival_time' => $row['arrival_time'],
                    'departure_time' => $row['departure_time'],
                    'day_offset' => $row['day_offset'],
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function assertValid(string $what, array $errors): void
    {
        if ($errors !== []) {
            throw new RuntimeException("{$what} is invalid: ".implode(' ', $errors));
        }
    }
}
