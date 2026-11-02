<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatClass;
use App\Gateways\Payment\MockGateway;
use App\Livewire\Booking\Checkout;
use App\Mail\TicketMail;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\Payment;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatInventoryService;
use App\Services\TrainRunService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
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
    $this->inventory = app(SeatInventoryService::class);
    $this->booking = payHeldBooking($this->passenger, [$this->seatIds[0], $this->seatIds[1]]);
});

/**
 * A held online booking with passengers saved, ready to pay.
 *
 * @param  list<int>  $seatIds
 */
function payHeldBooking(User $user, array $seatIds): Booking
{
    $booking = test()->inventory->hold(
        test()->run,
        array_map(fn (int $id) => ['coach_no' => 'KA', 'seat_id' => $id], $seatIds),
        1, 4, Channel::Online, $user,
    );

    return app(BookingService::class)->savePassengers(
        $booking,
        array_map(fn () => ['name' => 'Karim Uddin', 'age' => 40, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null], $seatIds),
        '01712345678',
        $user->email,
    );
}

/**
 * Start a payment and return the fields the mock pay page would post for $outcome.
 *
 * @return array<string, string>
 */
function gatewayCallback(Booking $booking, string $outcome, ?string $txnId = null, PaymentMethod $method = PaymentMethod::Bkash): array
{
    app(PaymentService::class)->start($booking, $method);
    $payment = $booking->payments()->latest('id')->firstOrFail();
    $gateway = app(MockGateway::class);

    return $gateway->callbackFields($payment, $outcome, $txnId ?? $gateway->newTxnId());
}

it('sends the passenger from checkout to the signed mock pay page', function () {
    Livewire::test(Checkout::class, ['booking' => $this->booking])
        ->set('method', 'nagad')
        ->call('pay')
        ->assertRedirectContains('/pay/mock/');

    $payment = Payment::sole();
    expect($payment->method)->toBe(PaymentMethod::Nagad)
        ->and($payment->status)->toBe(PaymentStatus::Initiated)
        ->and($payment->amount_poisha)->toBe(20000);

    $url = app(MockGateway::class)->startCheckout($payment);
    $this->get($url)->assertOk()->assertSee('Nagad')->assertSee('৳200')->assertSee($this->booking->pnr);
    $this->get("/pay/mock/{$payment->reference}")->assertForbidden(); // unsigned
});

it('confirms the booking, books the seats and emails the ticket on success', function () {
    $fields = gatewayCallback($this->booking, 'success', 'MOCKTXN1');

    $this->post('/payments/callback', $fields)
        ->assertRedirect(route('booking.show', $this->booking))
        ->assertSessionHas('payment_status');

    $this->booking->refresh();
    expect($this->booking->status)->toBe(BookingStatus::Confirmed)
        ->and($this->booking->seatBookings()->pluck('status')->unique()->all())->toBe([SeatBookingStatus::Booked])
        ->and(Payment::sole()->only(['status', 'txn_id']))->toBe(['status' => PaymentStatus::Success, 'txn_id' => 'MOCKTXN1']);

    Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => $mail->hasTo($this->passenger->email) && $mail->booking->is($this->booking));
});

it('ignores a duplicate callback', function () {
    $fields = gatewayCallback($this->booking, 'success');

    $this->post('/payments/callback', $fields)->assertRedirect();
    $this->postJson('/payments/callback', $fields)->assertOk()->assertJson(['outcome' => PaymentService::IGNORED, 'status' => 'success']);

    expect(Payment::count())->toBe(1)
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    Mail::assertSentCount(1);
});

it('rejects a callback with a bad signature or an unknown reference', function () {
    $fields = gatewayCallback($this->booking, 'failed');

    $this->post('/payments/callback', ['outcome' => 'success'] + $fields)->assertStatus(400);
    $this->post('/payments/callback', ['reference' => 'NOPE'] + $fields)->assertStatus(400);

    expect($this->booking->refresh()->status)->toBe(BookingStatus::PendingPayment)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Initiated);
});

it('fails the booking and returns the seats when the payment is declined', function () {
    $this->post('/payments/callback', gatewayCallback($this->booking, 'failed'))->assertRedirect(route('booking.show', $this->booking));

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Failed)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and(SeatBooking::taken()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('keeps the hold when the passenger cancels on the gateway page, so they can pay another way', function () {
    $this->post('/payments/callback', gatewayCallback($this->booking, 'cancelled'))->assertRedirect(route('booking.checkout', $this->booking));

    expect($this->booking->refresh()->isHeld())->toBeTrue()
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Failed);

    $this->post('/payments/callback', gatewayCallback($this->booking, 'success', method: PaymentMethod::Card));

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(Payment::where('status', PaymentStatus::Success->value)->sole()->method)->toBe(PaymentMethod::Card);
});

it('confirms a late payment when the expired seats are still free, even after holds:release', function () {
    $fields = gatewayCallback($this->booking, 'success');
    $this->travel(11)->minutes();
    $this->inventory->releaseExpired();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::Expired);

    $this->post('/payments/callback', $fields);

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($this->booking->seatBookings()->pluck('status')->unique()->all())->toBe([SeatBookingStatus::Booked]);
});

it('refunds a late payment in full when someone else bought the seat, and never sells it twice', function () {
    $fields = gatewayCallback($this->booking, 'success');
    $this->travel(11)->minutes();

    $other = tap(User::factory()->create())->assignRole(Role::Passenger->value);
    $rival = payHeldBooking($other, [$this->seatIds[1]]);
    $this->post('/payments/callback', gatewayCallback($rival, 'success'));
    expect($rival->refresh()->status)->toBe(BookingStatus::Confirmed);

    $this->post('/payments/callback', $fields)->assertSessionHas('payment_status', fn ($s) => str_contains($s[1], 'refunded'));

    $payment = Payment::where('reference', $fields['reference'])->sole();
    $refund = $payment->refunds()->sole();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Expired)
        ->and($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_poisha)->toBe(20000)
        ->and($refund->reason)->toBe(RefundReason::LatePayment)
        ->and($refund->status)->toBe(RefundStatus::Processed)
        ->and(SeatBooking::where('seat_id', $this->seatIds[1])->where('status', SeatBookingStatus::Booked->value)->count())->toBe(1);
});

it('refunds a second successful payment for a booking that is already paid', function () {
    $first = gatewayCallback($this->booking, 'success');
    $second = gatewayCallback($this->booking, 'success');

    $this->post('/payments/callback', $first);
    $this->post('/payments/callback', $second);

    $duplicate = Payment::where('reference', $second['reference'])->sole();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($duplicate->status)->toBe(PaymentStatus::Refunded)
        ->and($duplicate->refunds()->sole()->reason)->toBe(RefundReason::DuplicatePayment);
});

it('refunds a payment that arrives after the train run was cancelled', function () {
    $fields = gatewayCallback($this->booking, 'success');
    app(TrainRunService::class)->cancel($this->run, 'Flooding');
    expect($this->booking->refresh()->status)->toBe(BookingStatus::Cancelled);

    $this->post('/payments/callback', $fields);

    expect(Payment::sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Cancelled);
});

it('will not start a payment without passengers or after the hold expired', function () {
    $bare = $this->inventory->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $this->seatIds[5]]], 1, 4, Channel::Online, $this->passenger);

    Livewire::test(Checkout::class, ['booking' => $bare])->call('pay')->assertHasErrors('booking');

    $this->travel(11)->minutes();
    Livewire::test(Checkout::class, ['booking' => $this->booking])->call('pay')->assertHasErrors('booking');

    expect(Payment::count())->toBe(0);
});

it('records the cash payment for a counter sale', function () {
    $clerk = tap(User::factory()->create())->assignRole(Role::CounterClerk->value);

    $booking = app(BookingService::class)->counterSale(
        $this->run,
        [['coach_no' => 'KA', 'seat_id' => $this->seatIds[8]]],
        1, 4,
        [['name' => 'Rahim', 'age' => 30, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null]],
        $clerk,
    );

    $payment = $booking->payments()->sole();
    expect($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->status)->toBe(PaymentStatus::Success)
        ->and($payment->amount_poisha)->toBe(10000)
        ->and($payment->received_by)->toBe($clerk->id);
});
