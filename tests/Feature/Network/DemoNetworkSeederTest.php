<?php

use App\Models\Station;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoNetworkSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00')); // Monday
});

it('seeds the demo network', function () {
    $this->seed(DemoNetworkSeeder::class);

    expect(Station::count())->toBe(14)
        ->and(TrainRoute::count())->toBe(4)
        ->and(Train::count())->toBe(6)
        // 3 daily trains x 10 days + 3 Mon-Sat trains x 9 days (one Sunday in the window)
        ->and(TrainRun::count())->toBe(57);
});

it('builds the return routes with reversed stations and distances', function () {
    $this->seed(DemoNetworkSeeder::class);

    $up = TrainRoute::where('code', 'CTG-DHK')->firstOrFail()->stops()->with('station')->get();

    expect($up->first()->station->code)->toBe('CTG')
        ->and($up->first()->distance_km)->toBe(0)
        ->and($up->last()->station->code)->toBe('DHK')
        ->and($up->last()->distance_km)->toBe(320);
});

it('runs the overnight train into the next day', function () {
    $this->seed(DemoNetworkSeeder::class);

    $run = Train::where('number', '741')->firstOrFail()->runs()->whereDate('journey_date', '2026-11-02')->firstOrFail();
    $terminus = $run->train->scheduleStops()->get()->last();

    expect($run->scheduledDeparture()?->toDateTimeString())->toBe('2026-11-02 23:30:00')
        ->and($terminus->arrivalOn($run->journey_date)?->toDateTimeString())->toBe('2026-11-03 05:50:00');
});

it('can be re-run without duplicating anything', function () {
    $this->seed(DemoNetworkSeeder::class);
    $this->seed(DemoNetworkSeeder::class);

    expect(Station::count())->toBe(14)
        ->and(Train::count())->toBe(6)
        ->and(TrainRun::count())->toBe(57);
});
