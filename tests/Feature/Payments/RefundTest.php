<?php

use App\Contracts\PaymentGateway;
use App\Data\GatewayResult;
use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Enums\SeatClass;
use App\Exceptions\BookingRefusedException;
use App\Filament\Pages\CounterCancellation;
use App\Gateways\Payment\MockGateway;
use App\Livewire\Booking\BookingDetails;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\SeatInventoryService;
use App\Services\TrainRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoRefundRulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->seed([RolesAndPermissionsSeeder::class, DemoRefundRulesSeeder::class]); // 48h 90%, 24h 75%, 12h 50%; ৳20 fee
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable('08:00')
        ->withCoaches(1)
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create(['journey_date' => now()->addDays(10)->toDateString()]);
    $this->departure = CarbonImmutable::parse($this->run->journey_date->toDateString().' 08:00');
    $this->seatIds = $this->train->rakeCoaches()->first()->coachType->seats()->get()->modelKeys();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->passenger = tap(User::factory()->create())->assignRole(Role::Passenger->value);
    $this->refunds = app(RefundService::class);
});

/**
 * A confirmed online booking for two seats (৳200), paid through the mock gateway.
 */
function paidOnlineBooking(User $user, int $firstSeat = 0): Booking
{
    $seats = [test()->seatIds[$firstSeat], test()->seatIds[$firstSeat + 1]];
    $booking = app(SeatInventoryService::class)->hold(test()->run, array_map(fn ($id) => ['coach_no' => 'KA', 'seat_id' => $id], $seats), 1, 4, Channel::Online, $user);
    app(BookingService::class)->savePassengers($booking, array_map(fn () => ['name' => 'Karim', 'age' => 40, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null], $seats), '01712345678', $user->email);

    app(PaymentService::class)->start($booking, PaymentMethod::Bkash);
    $gateway = app(MockGateway::class);
    $fields = $gateway->callbackFields($booking->payments()->sole(), GatewayResult::SUCCESS, $gateway->newTxnId());
    app(PaymentService::class)->handleCallback($gateway->verifyCallback(Request::create('/', 'POST', $fields)));

    return $booking->refresh();
}

it('refunds by the rule for the time left: 3 days before departure is 90% less the fee', function () {
    $this->travelTo($this->departure->subDays(5));
    $booking = paidOnlineBooking($this->passenger);

    $this->travelTo($this->departure->subDays(3));
    $refund = $this->refunds->cancel($booking, $this->passenger);

    expect($refund->amount_poisha)->toBe(16000) // 90% of ৳200 = ৳180, less ৳20
        ->and($refund->percent)->toBe(90)
        ->and($refund->fee_poisha)->toBe(2000)
        ->and((float) $refund->hours_before)->toBe(72.0)
        ->and($refund->reason)->toBe(RefundReason::PassengerCancelled)
        ->and($refund->status)->toBe(RefundStatus::Processed)
        ->and($refund->txn_id)->toStartWith('MOCKRF')
        ->and($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancelled_by)->toBe($this->passenger->id)
        ->and($booking->paidPayment()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($booking->paidPayment()->refunded_poisha)->toBe(16000)
        ->and(SeatBooking::taken()->count())->toBe(0);
});

it('picks the rule with the most hours that still applies', function (int $hoursBefore, int $amount) {
    $this->travelTo($this->departure->subDays(5));
    $booking = paidOnlineBooking($this->passenger);

    expect($this->refunds->quote($booking, $this->departure->subHours($hoursBefore))->amountPoisha)->toBe($amount);
})->with([
    '48 h exactly' => [48, 16000],
    '30 h' => [30, 13000],  // 75% of ৳200 = ৳150, less ৳20
    '12 h' => [12, 8000],   // 50% = ৳100, less ৳20
    '11 h' => [11, 0],
]);

it('gives no refund 2 hours before departure, but still frees the seats', function () {
    $this->travelTo($this->departure->subDays(1));
    $booking = paidOnlineBooking($this->passenger);

    $this->travelTo($this->departure->subHours(2));
    $refund = $this->refunds->cancel($booking, $this->passenger);

    expect($refund)->toBeNull()
        ->and(Refund::count())->toBe(0)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->paidPayment()->status)->toBe(PaymentStatus::Success)
        ->and(SeatBooking::taken()->count())->toBe(0);
});

it('refuses to cancel after departure or twice', function () {
    $this->travelTo($this->departure->subDays(1));
    $booking = paidOnlineBooking($this->passenger);

    $this->travelTo($this->departure->addMinutes(1));
    expect(fn () => $this->refunds->cancel($booking))->toThrow(BookingRefusedException::class, 'already left');

    $this->travelTo($this->departure->subHours(30));
    $this->refunds->cancel($booking);
    expect(fn () => $this->refunds->cancel($booking))->toThrow(BookingRefusedException::class, 'Cancelled');
});

it('refunds every passenger in full when the planner cancels the run, and releases unpaid holds', function () {
    $this->travelTo($this->departure->subHours(3)); // too late for any rule: train cancellation still refunds 100%
    $a = paidOnlineBooking($this->passenger, 0);
    $b = paidOnlineBooking(tap(User::factory()->create())->assignRole(Role::Passenger->value), 2);
    $clerk = tap(User::factory()->create())->assignRole(Role::CounterClerk->value);
    $cash = app(BookingService::class)->counterSale($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seatIds[6]]], 1, 4,
        [['name' => 'Rahim', 'age' => 30, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null]], $clerk);
    $held = app(SeatInventoryService::class)->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seatIds[8]]], 1, 4, Channel::Online, $this->passenger);

    app(TrainRunService::class)->cancel($this->run, 'Track repairs');

    foreach ([$a, $b, $cash] as $booking) {
        $refund = $booking->refunds()->sole();
        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($refund->reason)->toBe(RefundReason::TrainCancelled)
            ->and($refund->amount_poisha)->toBe($booking->total_poisha)
            ->and($refund->fee_poisha)->toBe(0)
            ->and($refund->status)->toBe(RefundStatus::Processed)
            ->and($booking->paidPayment()->status)->toBe(PaymentStatus::Refunded);
    }

    expect($held->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and(SeatBooking::taken()->count())->toBe(0);
});

it('records a failed gateway refund and lets it be retried', function () {
    $this->travelTo($this->departure->subDays(5));
    $booking = paidOnlineBooking($this->passenger);

    $failing = new class extends MockGateway
    {
        public bool $fail = true;

        public function refund(Payment $payment, int $amountPoisha): GatewayResult
        {
            return $this->fail ? new GatewayResult(GatewayResult::FAILED, $payment->reference, message: 'Gateway timeout') : parent::refund($payment, $amountPoisha);
        }
    };
    $this->app->instance(PaymentGateway::class, $failing);
    $this->app->forgetInstance(RefundService::class);

    $refund = app(RefundService::class)->cancel($booking, $this->passenger);
    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_reason)->toBe('Gateway timeout')
        ->and($booking->paidPayment()->refunded_poisha)->toBe(0)
        ->and(app(RefundService::class)->quote($booking)->paidPoisha)->toBe(4000); // ৳160 stays reserved for the retry

    $failing->fail = false;
    $retried = app(RefundService::class)->process($refund);

    expect($retried->status)->toBe(RefundStatus::Processed)
        ->and($booking->paidPayment()->refunded_poisha)->toBe(16000);
});

it('lets the passenger cancel from their booking page and see the refund', function () {
    $this->travelTo($this->departure->subDays(5));
    $booking = paidOnlineBooking($this->passenger);
    $this->actingAs($this->passenger);

    $this->get(route('booking.show', $booking))->assertOk()->assertSee('Cancel and refund ৳160')->assertSee('Download ticket');

    Livewire::test(BookingDetails::class, ['booking' => $booking])
        ->call('cancelBooking')
        ->assertSee('৳160 is being refunded to your bKash account');

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);

    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Passenger->value))
        ->get(route('booking.show', $booking))->assertForbidden();
});

it('lets a counter clerk find a booking by PNR and refund a cash sale in cash', function () {
    $this->travelTo($this->departure->subDays(3)->subHour());
    $clerk = tap(User::factory()->create())->assignRole(Role::CounterClerk->value);
    $booking = app(BookingService::class)->counterSale($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seatIds[4]]], 1, 4,
        [['name' => 'Rahim', 'age' => 30, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null]], $clerk);
    $this->actingAs($clerk);

    Livewire::test(CounterCancellation::class)
        ->set('pnr', 'nope')->call('find')->assertHasErrors('pnr')
        ->set('pnr', strtolower($booking->pnr))->call('find')->assertHasNoErrors()
        ->assertSee('Cancel and refund ৳70') // 90% of ৳100 = ৳90, less ৳20
        ->call('cancelBooking')
        ->assertNotified("Cancelled: PNR {$booking->pnr}");

    $refund = $booking->refunds()->sole();
    expect($refund->reason)->toBe(RefundReason::CounterCancelled)
        ->and($refund->payment->method)->toBe(PaymentMethod::Cash)
        ->and($refund->status)->toBe(RefundStatus::Processed)
        ->and($refund->txn_id)->toBeNull()
        ->and($refund->requested_by)->toBe($clerk->id);
});
