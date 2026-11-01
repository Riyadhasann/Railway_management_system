<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\Role;
use App\Enums\SeatClass;
use App\Livewire\Booking\Checkout;
use App\Livewire\Booking\SelectSeats;
use App\Models\Booking;
use App\Models\Concession;
use App\Models\FareRule;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SeatInventoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable()
        ->withCoaches(1)
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create();
    $this->seatIds = $this->train->rakeCoaches()->first()->coachType->seats()->get()->modelKeys();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->passenger = tap(User::factory()->create())->assignRole(Role::Passenger->value);
    $this->actingAs($this->passenger);
});

function passengerRow(array $overrides = []): array
{
    return array_merge(['name' => 'Karim Uddin', 'age' => 40, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null], $overrides);
}

describe('seat selection', function () {
    it('shows the seat map for a valid segment and 404 for one the train does not run', function () {
        $this->get("/book/runs/{$this->run->id}/seats/1/3")->assertOk()->assertSee($this->train->label());
        $this->get("/book/runs/{$this->run->id}/seats/3/1")->assertNotFound();
        $this->get("/book/runs/{$this->run->id}/seats/1/9")->assertNotFound();
    });

    it('picks free seats up to the limit, then holds them and goes to checkout', function () {
        $component = Livewire::test(SelectSeats::class, ['run' => $this->run, 'from' => 1, 'to' => 3]);

        foreach (array_slice($this->seatIds, 0, 5) as $id) {
            $component->call('toggleSeat', 'KA', $id);
        }
        $component->assertCount('selected', 4)->assertSee('at most 4 seats');

        $component->call('toggleSeat', 'KA', $this->seatIds[3])->assertCount('selected', 3)
            ->assertSee('৳300')
            ->call('hold');

        $booking = Booking::sole();
        $component->assertRedirect(route('booking.checkout', $booking));
        expect($booking->user_id)->toBe($this->passenger->id)
            ->and($booking->seatBookings()->count())->toBe(3)
            ->and($booking->channel)->toBe(Channel::Online);
    });

    it('ignores taken seats and tells the passenger when a picked seat is taken meanwhile', function () {
        $inventory = app(SeatInventoryService::class);
        $inventory->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seatIds[0]]], 1, 4, Channel::Online);

        $component = Livewire::test(SelectSeats::class, ['run' => $this->run, 'from' => 1, 'to' => 3])
            ->call('toggleSeat', 'KA', $this->seatIds[0])
            ->assertSet('selected', [])
            ->call('toggleSeat', 'KA', $this->seatIds[1])
            ->assertSet('selected', ["KA|{$this->seatIds[1]}"]);

        $inventory->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seatIds[1]]], 2, 3, Channel::Counter);

        $component->call('refreshMap')
            ->assertSet('selected', [])
            ->assertSee('just been taken');
    });

    it('limits how often one user can try to hold seats', function () {
        $component = Livewire::test(SelectSeats::class, ['run' => $this->run, 'from' => 1, 'to' => 3]);

        foreach (range(1, SelectSeats::HOLDS_PER_MINUTE) as $i) {
            $component->call('hold'); // nothing selected: refused, but counted
        }

        $component->call('hold')->assertSee('Too many attempts');
    });
});

describe('checkout', function () {
    beforeEach(function () {
        $this->booking = app(SeatInventoryService::class)->hold(
            $this->run,
            [['coach_no' => 'KA', 'seat_id' => $this->seatIds[0]], ['coach_no' => 'KA', 'seat_id' => $this->seatIds[1]]],
            1, 4, Channel::Online, $this->passenger,
        );
    });

    it('is only open to the passenger who holds the booking', function () {
        $this->get("/book/checkout/{$this->booking->pnr}")->assertOk()->assertSee($this->booking->pnr)->assertSee('Seats held for');

        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Passenger->value))
            ->get("/book/checkout/{$this->booking->pnr}")->assertForbidden();
    });

    it('confirms the phone with an on-screen code, saves passengers and shows the total', function () {
        $child = Concession::create(['code' => 'CHILD', 'name' => 'Child', 'percent' => 50, 'max_age' => 11]);

        $component = Livewire::test(Checkout::class, ['booking' => $this->booking])
            ->set('passengers', [passengerRow(), passengerRow(['name' => 'Nabila', 'age' => 7, 'gender' => 'female', 'nid' => '', 'concession_id' => $child->id])])
            ->call('save')
            ->assertHasErrors('phone')
            ->set('phone', '01712345678')
            ->call('sendOtp');

        $code = $component->get('otpShown');
        expect($code)->toMatch('/^\d{6}$/');

        $component->set('otp', $code === '000000' ? '111111' : '000000')->call('verifyOtp')->assertHasErrors('otp')
            ->set('otp', $code)->call('verifyOtp')->assertHasNoErrors()->assertSet('verifiedPhone', '01712345678')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('৳150')
            ->assertSee('Online payment is not switched on yet');

        expect($this->booking->refresh()->total_poisha)->toBe(15000)
            ->and($this->booking->contact_phone)->toBe('01712345678')
            ->and($this->booking->passengers()->count())->toBe(2)
            ->and($this->booking->status)->toBe(BookingStatus::PendingPayment);
    });

    it('shows passenger rule errors next to the right fields', function () {
        Livewire::test(Checkout::class, ['booking' => $this->booking])
            ->set('phone', '01712345678')
            ->call('sendOtp')
            ->tap(fn ($c) => $c->set('otp', $c->get('otpShown'))->call('verifyOtp'))
            ->set('passengers', [passengerRow(['nid' => '']), passengerRow(['nid' => '123'])])
            ->call('save')
            ->assertHasErrors(['passengers.0.nid', 'passengers.1.nid']);
    });

    it('rejects a phone number in the wrong format', function () {
        Livewire::test(Checkout::class, ['booking' => $this->booking])
            ->set('phone', '12345')
            ->call('sendOtp')
            ->assertHasErrors('phone')
            ->assertSet('otpShown', null);
    });

    it('says when the hold has expired', function () {
        $this->travel(11)->minutes();

        $this->get("/book/checkout/{$this->booking->pnr}")->assertOk()->assertSee('hold has expired');
    });

    it('releases the seats when the passenger cancels', function () {
        Livewire::test(Checkout::class, ['booking' => $this->booking])
            ->call('cancel')
            ->assertRedirect(route('booking.search'));

        expect($this->booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });
});

it('throws a one-time code away after too many wrong tries', function () {
    $otp = app(OtpService::class);
    $code = $otp->send('test', '01712345678');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, OtpService::MAX_ATTEMPTS) as $i) {
        expect($otp->verify('test', '01712345678', $wrong))->toBeFalse();
    }

    expect($otp->verify('test', '01712345678', $code))->toBeFalse();

    $code = $otp->send('test', '01712345678');
    expect($otp->verify('test', '01899999999', $code))->toBeFalse()
        ->and($otp->verify('test', '01712345678', $code))->toBeTrue()
        ->and($otp->verify('test', '01712345678', $code))->toBeFalse(); // used once
});
