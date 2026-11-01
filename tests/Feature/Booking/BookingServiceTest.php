<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatClass;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\Concession;
use App\Models\FareRule;
use App\Models\Seat;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SeatInventoryService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable()
        ->withCoaches(1)
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create();
    $this->seats = $this->train->rakeCoaches()->first()->coachType->seats()->get();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->child = Concession::create(['code' => 'CHILD', 'name' => 'Child', 'percent' => 50, 'max_age' => 11]);
    $this->inventory = app(SeatInventoryService::class);
    $this->service = app(BookingService::class);
});

function adult(array $overrides = []): array
{
    return array_merge(['name' => 'Karim Uddin', 'age' => 40, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null], $overrides);
}

function holdSeats(int $count): Booking
{
    $seats = test()->seats->take($count)->map(fn (Seat $s) => ['coach_no' => 'KA', 'seat_id' => $s->id])->values()->all();

    return test()->inventory->hold(test()->run, $seats, 1, 4, Channel::Online, User::factory()->create());
}

it('saves one passenger per seat and recalculates the total with concessions', function () {
    $booking = holdSeats(2);

    $this->service->savePassengers($booking, [
        adult(),
        adult(['name' => 'Nabila', 'age' => 8, 'gender' => 'female', 'nid' => null, 'concession_id' => $this->child->id]),
    ], '01711000000', 'karim@example.test');

    $passengers = $booking->passengers()->orderBy('id')->get();
    expect($passengers)->toHaveCount(2)
        ->and($passengers[0]->fare_poisha)->toBe(10000)
        ->and($passengers[1]->fare_poisha)->toBe(5000)
        ->and($passengers[1]->seat_booking_id)->toBe($booking->seatBookings()->orderBy('id')->skip(1)->value('id'))
        ->and($booking->total_poisha)->toBe(15000)
        ->and($booking->contact_phone)->toBe('01711000000');
});

it('replaces passengers when saved again', function () {
    $booking = holdSeats(1);
    $this->service->savePassengers($booking, [adult()]);
    $this->service->savePassengers($booking, [adult(['name' => 'Someone Else'])]);

    expect($booking->passengers()->sole()->name)->toBe('Someone Else');
});

it('validates passengers: count, NID for adults, NID format, concession age and women-only seats', function () {
    Seat::whereKey($this->seats[0]->id)->update(['flags' => json_encode([Seat::FLAG_WOMEN])]);
    $booking = holdSeats(2);

    try {
        $this->service->savePassengers($booking, [
            adult(),                                                          // man on a women-only seat
            adult(['nid' => '12345', 'concession_id' => $this->child->id]),   // bad NID, adult child-concession
        ]);
        $this->fail('Expected validation errors');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['passengers.0.gender', 'passengers.1.nid', 'passengers.1.concession_id']);
    }

    expect(fn () => $this->service->savePassengers($booking, [adult()]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->savePassengers($booking, [adult(['gender' => 'female']), adult(['nid' => ''])]))->toThrow(ValidationException::class)
        ->and($booking->passengers()->count())->toBe(0);
});

it('does not take passenger details once the online hold has run out', function () {
    $booking = holdSeats(1);
    $this->travel(11)->minutes();

    $this->service->savePassengers($booking, [adult()]);
})->throws(BookingRefusedException::class, 'expired');

it('sells at the counter in one step', function () {
    $clerk = User::factory()->create();

    $booking = $this->service->counterSale($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seats[0]->id]], 2, 4, [adult()], $clerk, '01811000000');

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->channel)->toBe(Channel::Counter)
        ->and($booking->sold_by)->toBe($clerk->id)
        ->and($booking->user_id)->toBeNull()
        ->and($booking->passengers)->toHaveCount(1)
        ->and($booking->seatBookings()->value('status'))->toBe(SeatBookingStatus::Booked);
});

it('leaves nothing behind when a counter sale fails', function () {
    $clerk = User::factory()->create();
    $seat = [['coach_no' => 'KA', 'seat_id' => $this->seats[0]->id]];

    expect(fn () => $this->service->counterSale($this->run, $seat, 1, 4, [adult(['nid' => 'bad'])], $clerk))->toThrow(ValidationException::class)
        ->and(Booking::count())->toBe(0);

    holdSeats(1); // someone online holds the same seat
    expect(fn () => $this->service->counterSale($this->run, $seat, 1, 4, [adult()], $clerk))->toThrow(SeatUnavailableException::class)
        ->and(Booking::count())->toBe(1);
});
