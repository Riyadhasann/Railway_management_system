<?php

use App\Models\CoachType;
use App\Models\RakeCoach;
use App\Models\Seat;
use App\Models\Train;
use App\Models\TrainRun;
use App\Services\SeatInventoryService;
use Database\Seeders\DemoCoachesSeeder;
use Database\Seeders\DemoNetworkSeeder;

beforeEach(function () {
    $this->seed([DemoNetworkSeeder::class, DemoCoachesSeeder::class]);
});

it('seeds four coach types with layouts and a six-coach rake per train', function () {
    expect(CoachType::count())->toBe(4)
        ->and(Seat::count())->toBe(24 + 40 + 48 + 70)
        ->and(RakeCoach::count())->toBe(6 * 6)
        ->and(Train::first()->rakeCoaches()->pluck('coach_no')->all())->toBe(['KA', 'KHA', 'GA', 'GHA', 'CHA', 'JA']);
});

it('gives the AC berth coach lower and upper berths, and Shovan chair women-only seats', function () {
    $berths = CoachType::where('code', 'AC_B')->firstOrFail()->seats()->pluck('berth')->unique()->sort()->values()->all();
    $women = CoachType::where('code', 'S_CHAIR')->firstOrFail()->seats()->get()->filter->hasFlag(Seat::FLAG_WOMEN);

    expect($berths)->toBe(['lower', 'upper'])
        ->and($women->pluck('seat_no')->all())->toBe(['1', '2']);
});

it('renders a full seat map for a demo run', function () {
    $run = TrainRun::first();
    $service = app(SeatInventoryService::class);
    [$from, $to] = $service->fullJourney($run);

    $coaches = $service->availability($run, $from, $to);

    expect($coaches)->toHaveCount(6)
        ->and(array_sum(array_map(fn ($c) => $c->availableCount(), $coaches)))->toBe(24 + 40 + 48 + 48 + 70 + 70);
});

it('can be re-run without duplicating anything', function () {
    $this->seed(DemoCoachesSeeder::class);

    expect(CoachType::count())->toBe(4)->and(RakeCoach::count())->toBe(36);
});
