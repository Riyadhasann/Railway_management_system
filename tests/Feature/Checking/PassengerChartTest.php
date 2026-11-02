<?php

use App\Enums\BookingStatus;
use App\Enums\FineReason;
use App\Enums\Role;
use App\Enums\SeatClass;
use App\Exceptions\BookingRefusedException;
use App\Filament\Pages\PassengerChart;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\Fine;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CheckingService;
use App\Services\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable('23:00')
        ->withCoaches(1)
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create(['journey_date' => now()->toDateString()]);
    $seats = $this->train->rakeCoaches()->first()->coachType->seats()->get();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->tte = tap(User::factory()->create())->assignRole(Role::Tte->value);
    $clerk = tap(User::factory()->create())->assignRole(Role::CounterClerk->value);

    $sell = fn (int $seatIndex, string $name) => app(BookingService::class)->counterSale(
        $this->run, [['coach_no' => 'KA', 'seat_id' => $seats[$seatIndex]->id]], 1, 4,
        [['name' => $name, 'age' => 30, 'gender' => 'female', 'nid' => '1234567890', 'concession_id' => null]], $clerk,
    );
    $this->late = $sell(3, 'Shirin');
    $this->early = $sell(0, 'Ayesha');
    $this->checking = app(CheckingService::class);
});

it('lists ticket holders in seat order and shows who has been checked', function () {
    $cancelled = Booking::factory()->for($this->run)->create(['status' => BookingStatus::Cancelled]);
    app(TicketService::class)->validate(app(TicketService::class)->qrToken($this->early), $this->run, $this->tte);

    expect($this->checking->chart($this->run)->pluck('name')->all())->toBe(['Ayesha', 'Shirin'])
        ->and($this->checking->checkedBookingIds($this->run))->toBe([$this->early->id => true])
        ->and($cancelled->passengers()->count())->toBe(0);

    $this->actingAs($this->tte);
    Livewire::test(PassengerChart::class)
        ->set('runId', $this->run->id)
        ->assertSeeText('2 passengers · 1 bookings checked · 0 no-show')
        ->assertSee($this->late->pnr)
        ->assertSee('Checked');
});

it('marks and unmarks a no-show', function () {
    $passenger = $this->late->passengers()->sole();
    $this->actingAs($this->tte);

    $page = Livewire::test(PassengerChart::class)->set('runId', $this->run->id)->call('toggleNoShow', $passenger->id);
    expect($passenger->refresh()->isNoShow())->toBeTrue()
        ->and($passenger->no_show_by)->toBe($this->tte->id);
    $page->assertSeeText('1 no-show');

    $page->call('toggleNoShow', $passenger->id);
    expect($passenger->refresh()->isNoShow())->toBeFalse();
});

it('will not mark a no-show on a cancelled booking', function () {
    $this->late->update(['status' => BookingStatus::Cancelled]);

    expect(fn () => $this->checking->setNoShow($this->late->passengers()->sole(), true, $this->tte))
        ->toThrow(BookingRefusedException::class);
});

it('records a fine with a receipt number, optionally against a PNR on this train', function () {
    $this->actingAs($this->tte);

    Livewire::test(PassengerChart::class)
        ->set('runId', $this->run->id)
        ->set('fine', ['passenger_name' => 'Jamal', 'reason' => FineReason::NoTicket->value, 'amount' => '250', 'pnr' => '', 'note' => 'Boarded at Tongi'])
        ->call('recordFine')
        ->assertHasNoErrors()
        ->assertSet('fine.passenger_name', '')
        ->set('fine', ['passenger_name' => 'Shirin', 'reason' => FineReason::BeyondDestination->value, 'amount' => '80', 'pnr' => strtolower($this->late->pnr), 'note' => ''])
        ->call('recordFine')
        ->assertHasNoErrors()
        ->assertSeeText('Fines (2, ৳330)');

    $fines = Fine::orderBy('id')->get();
    expect($fines[0]->amount_poisha)->toBe(25000)
        ->and($fines[0]->booking_id)->toBeNull()
        ->and($fines[0]->receipt_no)->toStartWith('FN')
        ->and($fines[1]->booking_id)->toBe($this->late->id)
        ->and($fines[1]->tte_id)->toBe($this->tte->id);
});

it('rejects a fine without an amount or with a PNR from another train', function () {
    $other = Booking::factory()->confirmed()->create();

    try {
        $this->checking->recordFine($this->run, ['passenger_name' => 'X', 'reason' => 'no_ticket', 'amount_poisha' => 0, 'pnr' => $other->pnr], $this->tte);
        $this->fail('Expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['amount_poisha', 'pnr']);
    }

    expect(Fine::count())->toBe(0);
});

it('is open to TTEs and admins only', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/passenger-chart')
        ->assertStatus($status);
})->with([
    'tte' => [Role::Tte, 200],
    'admin' => [Role::Admin, 200],
    'counter clerk' => [Role::CounterClerk, 403],
]);
