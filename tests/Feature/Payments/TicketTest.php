<?php

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tickets = app(TicketService::class);
});

it('signs the QR token so it can be read back but not forged', function () {
    $booking = Booking::factory()->confirmed()->create(['pnr' => 'ABC234']);
    $token = $this->tickets->qrToken($booking);

    expect($token)->not->toContain('ABC234') // encoded, not the bare PNR
        ->and($this->tickets->parseToken($token))->toBe(['pnr' => 'ABC234', 'train_run_id' => $booking->train_run_id]);

    $forged = rtrim(strtr(base64_encode("XYZ789|{$booking->train_run_id}|".str_repeat('0', 64)), '+/', '-_'), '=');
    $otherRun = Booking::factory()->confirmed()->make(['pnr' => 'ABC234', 'train_run_id' => $booking->train_run_id + 1]);
    $swapped = explode('|', base64_decode(strtr($this->tickets->qrToken($otherRun), '-_', '+/')));
    $tampered = rtrim(strtr(base64_encode("ABC234|{$booking->train_run_id}|{$swapped[2]}"), '+/', '-_'), '=');

    expect($this->tickets->parseToken($forged))->toBeNull()
        ->and($this->tickets->parseToken($tampered))->toBeNull()
        ->and($this->tickets->parseToken('not a token'))->toBeNull()
        ->and($this->tickets->parseToken(''))->toBeNull();
});

it('makes a PNG QR code', function () {
    $booking = Booking::factory()->confirmed()->create();

    expect($this->tickets->qrDataUri($booking))->toStartWith('data:image/png;base64,');
});

describe('PDF download', function () {
    beforeEach(function () {
        $this->owner = tap(User::factory()->create())->assignRole(Role::Passenger->value);
        $this->run = TrainRun::factory()->create();
        $this->booking = Booking::factory()->confirmed()->for($this->run)->create(['user_id' => $this->owner->id]);
    });

    it('lets the owner download the ticket as a PDF', function () {
        $response = $this->actingAs($this->owner)->get(route('tickets.pdf', $this->booking));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($response->headers->get('content-disposition'))->toContain("ticket-{$this->booking->pnr}.pdf")
            ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
    });

    it('lets counter and finance staff print it, but not other passengers or a planner', function (Role $role, int $status) {
        $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
            ->get(route('tickets.pdf', $this->booking))
            ->assertStatus($status);
    })->with([
        'counter clerk' => [Role::CounterClerk, 200],
        'finance' => [Role::Finance, 200],
        'another passenger' => [Role::Passenger, 403],
        'planner' => [Role::Planner, 403],
    ]);

    it('has no ticket for a booking that is not confirmed', function () {
        $this->booking->update(['status' => BookingStatus::Cancelled]);

        $this->actingAs($this->owner)->get(route('tickets.pdf', $this->booking))->assertNotFound();
    });
});
