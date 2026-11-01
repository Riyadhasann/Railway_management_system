<?php

use App\Enums\SeatBookingStatus;
use App\Enums\SeatState;
use App\Exceptions\SeatUnavailableException;
use App\Models\CoachType;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;

beforeEach(function () {
    // Route of 4 stops (sequences 1..4), timetable over all stops, coaches KA and KHA of the same type (12 seats each).
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable()
        ->withCoaches(2)
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create();
    $this->seats = $this->train->rakeCoaches()->first()->coachType->seats()->get();
    $this->service = app(SeatInventoryService::class);
    $this->admin = User::factory()->create();
});

function stateOf(array $coaches, string $coachNo, int $seatId): SeatState
{
    foreach ($coaches as $coach) {
        if ($coach->coachNo === $coachNo) {
            foreach ($coach->seats as $seat) {
                if ($seat->seatId === $seatId) {
                    return $seat->state;
                }
            }
        }
    }

    throw new RuntimeException("Seat {$coachNo}/{$seatId} not on map");
}

function seatRow(TrainRun $run, string $coachNo, int $seatId, int $from, int $to, SeatBookingStatus $status, ?string $holdUntil = null): SeatBooking
{
    return SeatBooking::create([
        'train_run_id' => $run->id, 'coach_no' => $coachNo, 'seat_id' => $seatId,
        'from_seq' => $from, 'to_seq' => $to, 'status' => $status, 'hold_until' => $holdUntil,
    ]);
}

it('knows the full journey of a run', function () {
    expect($this->service->fullJourney($this->run))->toBe([1, 4]);
});

it('returns every coach with every seat, all available on an empty run', function () {
    $coaches = $this->service->availability($this->run, 1, 4);

    expect($coaches)->toHaveCount(2)
        ->and($coaches[0]->coachNo)->toBe('KA')
        ->and($coaches[1]->coachNo)->toBe('KHA')
        ->and($coaches[0]->seats)->toHaveCount(12)
        ->and($coaches[0]->availableCount())->toBe(12);
});

it('shows booked, blocked and active holds as taken, and expired holds as free', function () {
    [$a, $b, $c, $d] = $this->seats->take(4)->modelKeys();
    seatRow($this->run, 'KA', $a, 1, 4, SeatBookingStatus::Booked);
    seatRow($this->run, 'KA', $b, 1, 4, SeatBookingStatus::Blocked);
    seatRow($this->run, 'KA', $c, 1, 4, SeatBookingStatus::Held, now()->addMinutes(5));
    seatRow($this->run, 'KA', $d, 1, 4, SeatBookingStatus::Held, now()->subMinute());

    $map = $this->service->availability($this->run, 1, 4);

    expect(stateOf($map, 'KA', $a))->toBe(SeatState::Booked)
        ->and(stateOf($map, 'KA', $b))->toBe(SeatState::Blocked)
        ->and(stateOf($map, 'KA', $c))->toBe(SeatState::Held)
        ->and(stateOf($map, 'KA', $d))->toBe(SeatState::Available);
});

it('treats the same seat number in another coach as a different seat', function () {
    $seat = $this->seats->first()->id;
    seatRow($this->run, 'KA', $seat, 1, 4, SeatBookingStatus::Booked);

    $map = $this->service->availability($this->run, 1, 4);

    expect(stateOf($map, 'KA', $seat))->toBe(SeatState::Booked)
        ->and(stateOf($map, 'KHA', $seat))->toBe(SeatState::Available);
});

it('only counts overlapping segments (A to B and B to C can share a seat)', function () {
    $seat = $this->seats->first()->id;
    seatRow($this->run, 'KA', $seat, 1, 2, SeatBookingStatus::Booked); // stop 1 -> 2

    expect(stateOf($this->service->availability($this->run, 2, 4), 'KA', $seat))->toBe(SeatState::Available)
        ->and(stateOf($this->service->availability($this->run, 1, 3), 'KA', $seat))->toBe(SeatState::Booked);
});

it('ignores cancelled rows and other runs', function () {
    $seat = $this->seats->first()->id;
    seatRow($this->run, 'KA', $seat, 1, 4, SeatBookingStatus::Cancelled);
    $otherRun = TrainRun::factory()->for($this->train)->create(['journey_date' => now()->addDays(2)->toDateString()]);
    seatRow($otherRun, 'KA', $seat, 1, 4, SeatBookingStatus::Booked);

    expect(stateOf($this->service->availability($this->run, 1, 4), 'KA', $seat))->toBe(SeatState::Available);
});

it('blocks seats for a segment and records who did it', function () {
    [$a, $b] = $this->seats->take(2)->modelKeys();

    $count = $this->service->block($this->run, 'KA', [$a, $b], 1, 4, $this->admin, 'VIP quota');

    expect($count)->toBe(2)
        ->and(SeatBooking::where('status', 'blocked')->where('blocked_by', $this->admin->id)->where('reason', 'VIP quota')->count())->toBe(2)
        ->and(stateOf($this->service->availability($this->run, 1, 4), 'KA', $a))->toBe(SeatState::Blocked);
});

it('refuses to block a seat that is already booked on an overlapping segment', function () {
    $seat = $this->seats->first();
    seatRow($this->run, 'KA', $seat->id, 2, 3, SeatBookingStatus::Booked);

    expect(fn () => $this->service->block($this->run, 'KA', [$seat->id], 1, 4, $this->admin))
        ->toThrow(SeatUnavailableException::class, "KA-{$seat->seat_no}");

    expect(SeatBooking::where('status', 'blocked')->count())->toBe(0);
});

it('refuses seats that are not in the coach, and unknown coaches', function () {
    $foreign = CoachType::factory()->withSeats()->create()->seats()->first()->id;

    expect(fn () => $this->service->block($this->run, 'KA', [$foreign], 1, 4, $this->admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service->block($this->run, 'ZZ', [$this->seats->first()->id], 1, 4, $this->admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service->availability($this->run, 3, 3))->toThrow(InvalidArgumentException::class);
});

it('unblocks seats', function () {
    $seat = $this->seats->first()->id;
    $this->service->block($this->run, 'KA', [$seat], 1, 4, $this->admin);

    expect($this->service->unblock($this->run, 'KA', [$seat]))->toBe(1)
        ->and(stateOf($this->service->availability($this->run, 1, 4), 'KA', $seat))->toBe(SeatState::Available);
});
