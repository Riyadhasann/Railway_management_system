<?php

use App\Enums\BookingStatus;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\SeatBookingStatus;
use App\Models\Booking;
use App\Models\Concession;
use App\Models\Passenger;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $train = Train::factory()->withTimetable()->withCoaches(1)->create();
    $this->run = TrainRun::factory()->for($train)->create();
    $this->seatId = $train->rakeCoaches()->first()->coachType->seats()->first()->id;
});

function bookedSeat(Booking $booking, int $seatId): SeatBooking
{
    return $booking->seatBookings()->create([
        'train_run_id' => $booking->train_run_id, 'coach_no' => 'KA', 'seat_id' => $seatId,
        'from_seq' => 1, 'to_seq' => 2, 'status' => SeatBookingStatus::Held, 'hold_until' => $booking->hold_until,
    ]);
}

it('links a booking to its run, seats and passengers', function () {
    $booking = Booking::factory()->for($this->run)->create();
    $seat = bookedSeat($booking, $this->seatId);
    $passenger = Passenger::create([
        'booking_id' => $booking->id, 'seat_booking_id' => $seat->id,
        'name' => 'Rahima Akter', 'age' => 30, 'gender' => Gender::Female, 'fare_poisha' => 50000,
    ]);

    expect($booking->trainRun->is($this->run))->toBeTrue()
        ->and($this->run->bookings)->toHaveCount(1)
        ->and($seat->booking->is($booking))->toBeTrue()
        ->and($booking->passengers->first()->is($passenger))->toBeTrue()
        ->and($passenger->seatBooking->is($seat))->toBeTrue()
        ->and($passenger->gender)->toBe(Gender::Female);
});

it('knows whether the hold is still running', function () {
    $held = Booking::factory()->for($this->run)->create(['hold_until' => now()->addMinutes(5)]);
    $expired = Booking::factory()->for($this->run)->create(['hold_until' => now()->subMinute()]);
    $confirmed = Booking::factory()->for($this->run)->confirmed()->create();

    expect($held->isHeld())->toBeTrue()
        ->and($held->holdSecondsLeft())->toBeGreaterThan(290)
        ->and($expired->isHeld())->toBeFalse()
        ->and($expired->holdSecondsLeft())->toBe(0)
        ->and($confirmed->isHeld())->toBeFalse()
        ->and($confirmed->status)->toBe(BookingStatus::Confirmed);
});

it('uses the PNR in URLs and keeps it unique', function () {
    $booking = Booking::factory()->for($this->run)->create(['pnr' => 'ABC234']);

    expect($booking->getRouteKey())->toBe('ABC234')
        ->and(fn () => Booking::factory()->for($this->run)->create(['pnr' => 'ABC234']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('does not let an admin delete a concession that a passenger has used', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $used = Concession::create(['code' => 'CHILD', 'name' => 'Child', 'percent' => 50]);
    $unused = Concession::create(['code' => 'SENIOR', 'name' => 'Senior', 'percent' => 25]);
    $booking = Booking::factory()->for($this->run)->create();
    Passenger::create([
        'booking_id' => $booking->id, 'seat_booking_id' => bookedSeat($booking, $this->seatId)->id,
        'name' => 'Child', 'age' => 8, 'gender' => Gender::Male, 'concession_id' => $used->id, 'fare_poisha' => 25000,
    ]);

    expect($admin->can('delete', $used))->toBeFalse()
        ->and($admin->can('delete', $unused))->toBeTrue();
});
