<?php

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\ScanResult;
use App\Models\Booking;
use App\Models\TicketScan;
use App\Models\Train;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tickets = app(TicketService::class);
    $this->tte = tap(User::factory()->create(['name' => 'Rafiq TTE']))->assignRole(Role::Tte->value);
    $this->run = TrainRun::factory()->create(['journey_date' => now()->toDateString()]);
    $this->booking = Booking::factory()->confirmed()->for($this->run)->create();
});

it('accepts a valid ticket once, then shows it as already used', function () {
    $token = $this->tickets->qrToken($this->booking);

    $first = $this->tickets->validate($token, $this->run, $this->tte);
    $this->travel(20)->minutes();
    $second = $this->tickets->validate($token, $this->run, $this->tte);

    expect($first->result)->toBe(ScanResult::Valid)
        ->and($first->isValid())->toBeTrue()
        ->and($first->booking->is($this->booking))->toBeTrue()
        ->and($second->result)->toBe(ScanResult::AlreadyUsed)
        ->and($second->firstScan->is($first->scan))->toBeTrue()
        ->and($second->message)->toContain('Rafiq TTE');

    expect(TicketScan::where('booking_id', $this->booking->id)->orderBy('id')->pluck('result')->all())
        ->toBe([ScanResult::Valid, ScanResult::AlreadyUsed]);
});

it('rejects a cancelled ticket', function () {
    $this->booking->update(['status' => BookingStatus::Cancelled]);

    expect($this->tickets->validate($this->tickets->qrToken($this->booking), $this->run, $this->tte)->result)
        ->toBe(ScanResult::Cancelled);
});

it('rejects a ticket for the same train on another date', function () {
    $tomorrow = TrainRun::factory()->for($this->run->train)->create(['journey_date' => now()->addDay()->toDateString()]);
    $booking = Booking::factory()->confirmed()->for($tomorrow)->create();

    $outcome = $this->tickets->validate($this->tickets->qrToken($booking), $this->run, $this->tte);

    expect($outcome->result)->toBe(ScanResult::WrongDate)
        ->and($outcome->message)->toContain($tomorrow->journey_date->format('d M Y'))
        ->and($outcome->scan->train_run_id)->toBe($this->run->id);
});

it('rejects a ticket for a different train', function () {
    $other = TrainRun::factory()->for(Train::factory())->create(['journey_date' => now()->toDateString()]);
    $booking = Booking::factory()->confirmed()->for($other)->create();

    expect($this->tickets->validate($this->tickets->qrToken($booking), $this->run, $this->tte)->result)
        ->toBe(ScanResult::Invalid);
});

it('rejects an unpaid booking and a forged or unreadable code, and records every scan', function () {
    $unpaid = Booking::factory()->for($this->run)->create(['status' => BookingStatus::PendingPayment]);
    $forged = rtrim(strtr(base64_encode("{$this->booking->pnr}|{$this->run->id}|".str_repeat('a', 64)), '+/', '-_'), '=');

    expect($this->tickets->validate($this->tickets->qrToken($unpaid), $this->run, $this->tte)->result)->toBe(ScanResult::Invalid)
        ->and($this->tickets->validate($forged, $this->run, $this->tte)->result)->toBe(ScanResult::Invalid)
        ->and($this->tickets->validate('hello', $this->run, $this->tte)->result)->toBe(ScanResult::Invalid);

    expect(TicketScan::count())->toBe(3)
        ->and(TicketScan::whereNull('booking_id')->count())->toBe(2)
        ->and(TicketScan::where('tte_id', $this->tte->id)->count())->toBe(3)
        // A forged code never counts as a check of the real ticket.
        ->and($this->tickets->validate($this->tickets->qrToken($this->booking), $this->run, $this->tte)->result)->toBe(ScanResult::Valid);
});
