<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\RunStatus;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatClass;
use App\Enums\SeatState;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // 4 stops 50 km apart (sequences 1..4), coaches KA and KHA of one 12-seat Shovan chair type.
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable()
        ->withCoaches(2)
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create(); // tomorrow
    $this->seatIds = $this->train->rakeCoaches()->first()->coachType->seats()->get()->modelKeys();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->service = app(SeatInventoryService::class);
    $this->passenger = User::factory()->create();
});

function seatsOf(string $coachNo, int ...$seatIds): array
{
    return array_map(fn (int $id) => ['coach_no' => $coachNo, 'seat_id' => $id], $seatIds);
}

it('holds seats: one pending booking with a PNR, held seat rows and a fare total', function () {
    [$a, $b] = $this->seatIds;

    $booking = $this->service->hold($this->run, seatsOf('KA', $a, $b), 1, 3, Channel::Online, $this->passenger);

    expect($booking->status)->toBe(BookingStatus::PendingPayment)
        ->and($booking->pnr)->toMatch('/^[2-9A-HJ-NP-Z]{6}$/')
        ->and($booking->user_id)->toBe($this->passenger->id)
        ->and($booking->total_poisha)->toBe(20000)
        ->and($booking->isHeld())->toBeTrue()
        ->and($booking->hold_until->diffInMinutes(now()->addMinutes(10), true))->toBeLessThan(1)
        ->and($booking->seatBookings)->toHaveCount(2)
        ->and($booking->seatBookings->pluck('status')->unique()->all())->toBe([SeatBookingStatus::Held]);
});

it('refuses a seat that is already held, booked or blocked on an overlapping segment', function () {
    $seat = $this->seatIds[0];
    $this->service->hold($this->run, seatsOf('KA', $seat), 1, 3, Channel::Online, $this->passenger);

    expect(fn () => $this->service->hold($this->run, seatsOf('KA', $seat), 2, 4, Channel::Online, User::factory()->create()))
        ->toThrow(SeatUnavailableException::class, 'KA-1');

    expect(Booking::count())->toBe(1);
});

it('sells A to B and B to C on the same seat', function () {
    $seat = $this->seatIds[0];

    $first = $this->service->hold($this->run, seatsOf('KA', $seat), 1, 2, Channel::Online, $this->passenger);
    $second = $this->service->hold($this->run, seatsOf('KA', $seat), 2, 4, Channel::Online, User::factory()->create());

    expect($first->exists && $second->exists)->toBeTrue()
        ->and(SeatBooking::where('seat_id', $seat)->where('coach_no', 'KA')->count())->toBe(2);
});

it('lets someone else take a seat once the hold has run out', function () {
    $seat = $this->seatIds[0];
    $this->service->hold($this->run, seatsOf('KA', $seat), 1, 4, Channel::Online, $this->passenger);

    $this->travel(11)->minutes();

    expect($this->service->hold($this->run, seatsOf('KA', $seat), 1, 4, Channel::Online, User::factory()->create())->exists)->toBeTrue();
});

it('keeps counter-quota seats for the counter', function () {
    $this->train->rakeCoaches()->where('coach_no', 'KHA')->update(['counter_quota' => 2]);
    $quotaSeat = end($this->seatIds); // last seat in layout order
    $normalSeat = $this->seatIds[0];

    expect(fn () => $this->service->hold($this->run, seatsOf('KHA', $quotaSeat), 1, 4, Channel::Online, $this->passenger))
        ->toThrow(BookingRefusedException::class, 'counter');

    $online = $this->service->availability($this->run, 1, 4, Channel::Online);
    $counter = $this->service->availability($this->run, 1, 4, Channel::Counter);

    expect($online[1]->count(SeatState::Blocked))->toBe(2)
        ->and($counter[1]->count(SeatState::Blocked))->toBe(0)
        ->and($this->service->hold($this->run, seatsOf('KHA', $quotaSeat), 1, 4, Channel::Counter)->exists)->toBeTrue()
        ->and($this->service->hold($this->run, seatsOf('KHA', $normalSeat), 1, 4, Channel::Online, $this->passenger)->exists)->toBeTrue();
});

it('refuses cancelled runs, departed trains, stops the train skips and too many seats', function () {
    $seat = $this->seatIds[0];

    $cancelled = TrainRun::factory()->for($this->train)->create(['journey_date' => now()->addDays(3)->toDateString(), 'status' => RunStatus::Cancelled]);
    expect(fn () => $this->service->hold($cancelled, seatsOf('KA', $seat), 1, 4, Channel::Online))->toThrow(BookingRefusedException::class, 'cancelled');

    $past = TrainRun::factory()->for($this->train)->create(['journey_date' => now()->subDay()->toDateString()]);
    expect(fn () => $this->service->hold($past, seatsOf('KA', $seat), 1, 4, Channel::Online))->toThrow(BookingRefusedException::class, 'already left');

    expect(fn () => $this->service->hold($this->run, seatsOf('KA', $seat), 1, 9, Channel::Online))->toThrow(BookingRefusedException::class, 'does not stop')
        ->and(fn () => $this->service->hold($this->run, seatsOf('KA', ...array_slice($this->seatIds, 0, 5)), 1, 4, Channel::Online))->toThrow(BookingRefusedException::class, 'at most 4')
        ->and(fn () => $this->service->hold($this->run, [], 1, 4, Channel::Online))->toThrow(BookingRefusedException::class)
        ->and(fn () => $this->service->hold($this->run, seatsOf('ZZ', $seat), 1, 4, Channel::Online))->toThrow(InvalidArgumentException::class);
});

it('confirms a held booking: seats booked, booking confirmed, and confirming twice is harmless', function () {
    $booking = $this->service->hold($this->run, seatsOf('KA', $this->seatIds[0]), 1, 4, Channel::Online, $this->passenger);

    $this->service->confirm($booking);
    $this->service->confirm($booking);

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->confirmed_at)->not->toBeNull()
        ->and($booking->seatBookings()->pluck('status')->unique()->all())->toBe([SeatBookingStatus::Booked]);
});

it('confirms a late booking if nobody took the seats meanwhile', function () {
    $booking = $this->service->hold($this->run, seatsOf('KA', $this->seatIds[0]), 1, 4, Channel::Online, $this->passenger);
    $this->travel(15)->minutes();

    expect($this->service->confirm($booking)->status)->toBe(BookingStatus::Confirmed);
});

it('refuses a late booking whose seat was sold to someone else, so it is never sold twice', function () {
    $seat = $this->seatIds[0];
    $late = $this->service->hold($this->run, seatsOf('KA', $seat), 1, 4, Channel::Online, $this->passenger);
    $this->travel(15)->minutes();
    $other = $this->service->hold($this->run, seatsOf('KA', $seat), 2, 3, Channel::Online, User::factory()->create());

    expect(fn () => $this->service->confirm($late))->toThrow(HoldExpiredException::class);

    expect($late->refresh()->status)->toBe(BookingStatus::Expired)
        ->and($late->seatBookings()->value('status'))->toBe(SeatBookingStatus::Cancelled)
        ->and($other->refresh()->status)->toBe(BookingStatus::PendingPayment);
});

it('releases a hold on request', function () {
    $seat = $this->seatIds[0];
    $booking = $this->service->hold($this->run, seatsOf('KA', $seat), 1, 4, Channel::Online, $this->passenger);

    $this->service->release($booking);

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($this->service->hold($this->run, seatsOf('KA', $seat), 1, 4, Channel::Online)->exists)->toBeTrue();
});

it('expires only run-out holds with holds:release', function () {
    $old = $this->service->hold($this->run, seatsOf('KA', $this->seatIds[0]), 1, 4, Channel::Online, $this->passenger);
    $this->travel(11)->minutes();
    $fresh = $this->service->hold($this->run, seatsOf('KA', $this->seatIds[1]), 1, 4, Channel::Online, $this->passenger);
    $paid = $this->service->confirm($this->service->hold($this->run, seatsOf('KA', $this->seatIds[2]), 1, 4, Channel::Counter));

    Artisan::call('holds:release');

    expect(Artisan::output())->toContain('Released 1 expired hold(s).')
        ->and($old->refresh()->status)->toBe(BookingStatus::Expired)
        ->and($old->seatBookings()->value('status'))->toBe(SeatBookingStatus::Cancelled)
        ->and($fresh->refresh()->status)->toBe(BookingStatus::PendingPayment)
        ->and($paid->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('schedules holds:release every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'holds:release'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
