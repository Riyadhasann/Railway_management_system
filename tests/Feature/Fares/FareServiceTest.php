<?php

use App\Enums\SeatClass;
use App\Exceptions\FareNotFoundException;
use App\Models\Concession;
use App\Models\FareRule;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Services\FareService;
use Database\Seeders\DemoFaresSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(FareService::class);
});

function fareRule(SeatClass $class, int $from, int $to, int $taka): FareRule
{
    return FareRule::create(['seat_class' => $class, 'km_from' => $from, 'km_to' => $to, 'fare_poisha' => $taka * 100]);
}

it('finds the fare for the band that contains the distance, bounds included', function () {
    fareRule(SeatClass::ShovanChair, 0, 50, 60);
    fareRule(SeatClass::ShovanChair, 51, 100, 110);
    fareRule(SeatClass::AcChair, 0, 100, 250);

    expect($this->service->fareFor(SeatClass::ShovanChair, 50))->toBe(6000)
        ->and($this->service->fareFor(SeatClass::ShovanChair, 51))->toBe(11000)
        ->and($this->service->fareFor(SeatClass::AcChair, 51))->toBe(25000);
});

it('throws when no rule covers the distance', function () {
    fareRule(SeatClass::ShovanChair, 0, 50, 60);

    $this->service->fareFor(SeatClass::ShovanChair, 51);
})->throws(FareNotFoundException::class);

it('quotes each seat by its coach class over the route distance', function () {
    // 4 stops, 50 km apart; coaches KA and KHA are Shovan chair.
    $train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable()
        ->withCoaches(2)
        ->create();
    $run = TrainRun::factory()->for($train)->create();
    $seatId = $train->rakeCoaches()->first()->coachType->seats()->first()->id;
    fareRule(SeatClass::ShovanChair, 0, 50, 60);
    fareRule(SeatClass::ShovanChair, 51, 100, 110);

    $quote = $this->service->quote($run, [
        ['coach_no' => 'KA', 'seat_id' => $seatId],
        ['coach_no' => 'KHA', 'seat_id' => $seatId],
    ], 2, 4);

    expect($quote->distanceKm)->toBe(100)
        ->and($quote->forSeat('KA', $seatId))->toBe(11000)
        ->and($quote->total())->toBe(22000);
});

it('takes the concession percentage off a fare', function () {
    $child = new Concession(['percent' => 50]);
    $senior = new Concession(['percent' => 25]);

    expect($this->service->applyConcession(11000, null))->toBe(11000)
        ->and($this->service->applyConcession(11000, $child))->toBe(5500)
        ->and($this->service->applyConcession(11050, $senior))->toBe(8288); // 82.875 taka rounds up
});

it('checks concession age limits', function () {
    $child = new Concession(['percent' => 50, 'max_age' => 11]);
    $senior = new Concession(['percent' => 25, 'min_age' => 65]);

    expect($child->allowsAge(11))->toBeTrue()
        ->and($child->allowsAge(12))->toBeFalse()
        ->and($senior->allowsAge(64))->toBeFalse()
        ->and($senior->allowsAge(65))->toBeTrue();
});

it('rejects fare bands that overlap another band of the same class', function () {
    $rule = fareRule(SeatClass::Snigdha, 0, 50, 100);

    expect($this->service->bandErrors(SeatClass::Snigdha, 40, 80))->toHaveCount(1)
        ->and($this->service->bandErrors(SeatClass::Snigdha, 51, 100))->toBe([])
        ->and($this->service->bandErrors(SeatClass::AcChair, 0, 50))->toBe([])
        ->and($this->service->bandErrors(SeatClass::Snigdha, 0, 60, ignoreId: $rule->id))->toBe([])
        ->and($this->service->bandErrors(SeatClass::Snigdha, 80, 60))->toBe(['The band must end at or after its start.']);
});

it('seeds fares for every class up to 400 km with no gaps, and two concessions', function () {
    $this->seed(DemoFaresSeeder::class);
    $this->seed(DemoFaresSeeder::class); // re-run is a no-op

    foreach (SeatClass::cases() as $class) {
        foreach ([0, 1, 50, 51, 320, 400] as $km) {
            expect($this->service->fareFor($class, $km))->toBeGreaterThan(0);
        }
    }

    expect(FareRule::count())->toBe(32)
        ->and(Concession::count())->toBe(2)
        ->and($this->service->fareFor(SeatClass::AcBerth, 320))->toBeGreaterThan($this->service->fareFor(SeatClass::ShovanChair, 320));
});

it('records fare rule changes in the audit log', function () {
    $rule = fareRule(SeatClass::Snigdha, 0, 50, 100);
    $rule->update(['fare_poisha' => 12000]);

    expect(Activity::where('subject_type', $rule->getMorphClass())->where('subject_id', $rule->id)->count())->toBe(2);
});
