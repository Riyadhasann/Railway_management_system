<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\Role;
use App\Enums\SeatClass;
use App\Filament\Pages\CounterBooking;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable()
        ->withCoaches(1)
        ->create();
    $this->train->rakeCoaches()->update(['counter_quota' => 2]);
    $this->run = TrainRun::factory()->for($this->train)->create(); // tomorrow
    $this->seatIds = $this->train->rakeCoaches()->first()->coachType->seats()->get()->modelKeys();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->clerk = tap(User::factory()->create())->assignRole(Role::CounterClerk->value);
});

function counterPage(): Testable
{
    return Livewire::test(CounterBooking::class)
        ->set('date', test()->run->journey_date->toDateString())
        ->set('runId', test()->run->id);
}

it('is open to counter clerks and admins only', function () {
    $this->actingAs($this->clerk)->get('/admin/counter-booking')->assertOk()->assertSee('Counter booking');
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Admin->value))->get('/admin/counter-booking')->assertOk();
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Planner->value))->get('/admin/counter-booking')->assertForbidden();
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Finance->value))->get('/admin/counter-booking')->assertForbidden();
});

it('sells a counter-quota seat for cash in one step', function () {
    $this->actingAs($this->clerk);
    $quotaSeat = end($this->seatIds);
    $key = "KA|{$quotaSeat}";

    counterPage()
        ->assertSet('fromSeq', 1)->assertSet('toSeq', 4)
        ->set('fromSeq', 2)
        ->call('toggleSeat', 'KA', $quotaSeat)
        ->assertSet('selected', [$key])
        ->set("passengers.{$key}", ['name' => 'Rahim', 'age' => 35, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null])
        ->set('phone', '01811000000')
        ->call('sell')
        ->assertHasNoErrors()
        ->assertSet('selected', [])
        ->assertNotified();

    $booking = Booking::sole();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->channel)->toBe(Channel::Counter)
        ->and($booking->sold_by)->toBe($this->clerk->id)
        ->and([$booking->from_seq, $booking->to_seq])->toBe([2, 4])
        ->and($booking->contact_phone)->toBe('01811000000')
        ->and($booking->passengers()->sole()->name)->toBe('Rahim');
});

it('shows passenger rule errors against the right seat and sells nothing', function () {
    $this->actingAs($this->clerk);
    [$a, $b] = $this->seatIds;

    counterPage()
        ->call('toggleSeat', 'KA', $a)
        ->call('toggleSeat', 'KA', $b)
        ->set("passengers.KA|{$a}", ['name' => 'Rahim', 'age' => 35, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null])
        ->set("passengers.KA|{$b}", ['name' => 'Karim', 'age' => 40, 'gender' => 'male', 'nid' => '12', 'concession_id' => null])
        ->call('sell')
        ->assertHasErrors(["passengers.KA|{$b}.nid"])
        ->assertHasNoErrors(["passengers.KA|{$a}.nid"]);

    expect(Booking::count())->toBe(0);
});

it('cannot pick a seat already sold online, and refuses when a seat goes meanwhile', function () {
    $this->actingAs($this->clerk);
    [$a, $b] = $this->seatIds;
    $inventory = app(SeatInventoryService::class);
    $inventory->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $a]], 1, 4, Channel::Online);

    $page = counterPage()
        ->call('toggleSeat', 'KA', $a)
        ->assertSet('selected', [])
        ->call('toggleSeat', 'KA', $b)
        ->set("passengers.KA|{$b}", ['name' => 'Rahim', 'age' => 35, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null]);

    $inventory->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $b]], 1, 2, Channel::Online);

    $page->call('sell')->assertNotified('Not sold');
    expect(Booking::where('channel', Channel::Counter->value)->count())->toBe(0);
});

it('only lists scheduled runs on the chosen date', function () {
    $this->actingAs($this->clerk);

    Livewire::test(CounterBooking::class)
        ->set('date', $this->run->journey_date->toDateString())
        ->assertSee($this->train->label())
        ->set('date', $this->run->journey_date->addDays(4)->toDateString())
        ->assertSee('No scheduled trains on this date.');
});
