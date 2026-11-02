<?php

use App\Enums\Role;
use App\Enums\RunStatus;
use App\Enums\ScanResult;
use App\Filament\Pages\TicketScanner;
use App\Models\Booking;
use App\Models\TicketScan;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tte = tap(User::factory()->create())->assignRole(Role::Tte->value);
    $this->run = TrainRun::factory()->create(['journey_date' => now()->toDateString()]);
});

it('is open to TTEs and admins only', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/ticket-scanner')
        ->assertStatus($status);
})->with([
    'tte' => [Role::Tte, 200],
    'admin' => [Role::Admin, 200],
    'counter clerk' => [Role::CounterClerk, 403],
    'station master' => [Role::StationMaster, 403],
]);

it('offers only today\'s and yesterday\'s running trains, and picks the only one', function () {
    TrainRun::factory()->create(['journey_date' => now()->addDay()->toDateString()]);
    TrainRun::factory()->create(['journey_date' => now()->toDateString(), 'status' => RunStatus::Cancelled]);
    $this->actingAs($this->tte);

    Livewire::test(TicketScanner::class)
        ->assertSet('runId', $this->run->id)
        ->tap(fn ($c) => expect($c->instance()->runs->modelKeys())->toBe([$this->run->id]));
});

it('checks a scanned code and shows the result, then already used', function () {
    $booking = Booking::factory()->confirmed()->for($this->run)->create();
    $token = app(TicketService::class)->qrToken($booking);
    $this->actingAs($this->tte);

    Livewire::test(TicketScanner::class)
        ->set('runId', $this->run->id)
        ->call('scan', $token)
        ->assertSet('last.result', ScanResult::Valid->value)
        ->assertSee($booking->pnr)
        ->assertSeeText('1 of 1 bookings checked')
        ->set('code', $token)
        ->call('scan')
        ->assertSet('last.result', ScanResult::AlreadyUsed->value)
        ->assertSet('code', '');

    expect(TicketScan::where('tte_id', $this->tte->id)->count())->toBe(2);
});

it('does nothing until a train is chosen', function () {
    TrainRun::factory()->create(['journey_date' => now()->toDateString()]); // two runs: no default
    $this->actingAs($this->tte);

    Livewire::test(TicketScanner::class)
        ->assertSet('runId', null)
        ->call('scan', 'anything')
        ->assertSet('last', null);

    expect(TicketScan::count())->toBe(0);
});
