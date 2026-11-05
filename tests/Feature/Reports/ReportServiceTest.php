<?php

use App\Data\GatewayResult;
use App\Enums\Channel;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Enums\SeatClass;
use App\Gateways\Payment\MockGateway;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ReportService;
use App\Services\SeatInventoryService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoRefundRulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->seed([RolesAndPermissionsSeeder::class, DemoRefundRulesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));

    $this->train = Train::factory()->state(['train_route_id' => TrainRoute::factory()->withStops(4)])->withTimetable('08:00')->withCoaches(1)->create(); // 12 seats
    $this->run = TrainRun::factory()->for($this->train)->create(['journey_date' => '2026-11-10']);
    $this->seats = $this->train->rakeCoaches()->first()->coachType->seats()->pluck('id')->all();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);
    $this->clerk = tap(User::factory()->create())->assignRole(Role::CounterClerk->value);
    $this->reports = app(ReportService::class);

    // Yesterday (Nov 4): one cash seat, ৳100.
    reportCounterSale([$this->seats[0]]);

    // Today (Nov 5): two cash seats (৳200) and one online seat (৳100) that is then cancelled 5 days out (refund 90% − ৳20 = ৳70).
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
    reportCounterSale([$this->seats[1], $this->seats[2]]);
    $this->online = reportOnlineSale($this->seats[3]);
    app(RefundService::class)->cancel($this->online);
});

/**
 * @param  list<int>  $seatIds
 */
function reportCounterSale(array $seatIds): Booking
{
    return app(BookingService::class)->counterSale(
        test()->run,
        array_map(fn ($id) => ['coach_no' => 'KA', 'seat_id' => $id], $seatIds),
        1, 4,
        array_map(fn () => ['name' => 'Rahim', 'age' => 30, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null], $seatIds),
        test()->clerk,
    );
}

function reportOnlineSale(int $seatId): Booking
{
    $user = tap(User::factory()->create())->assignRole(Role::Passenger->value);
    $booking = app(SeatInventoryService::class)->hold(test()->run, [['coach_no' => 'KA', 'seat_id' => $seatId]], 1, 4, Channel::Online, $user);
    app(BookingService::class)->savePassengers($booking, [['name' => 'Karim', 'age' => 40, 'gender' => 'male', 'nid' => '1234567890', 'concession_id' => null]], '01712345678', $user->email);
    app(PaymentService::class)->start($booking, PaymentMethod::Bkash);

    $gateway = app(MockGateway::class);
    $fields = $gateway->callbackFields($booking->payments()->sole(), GatewayResult::SUCCESS, $gateway->newTxnId());
    app(PaymentService::class)->handleCallback($gateway->verifyCallback(Request::create('/', 'POST', $fields)));

    return $booking->refresh();
}

it('gives daily revenue as successful payments minus refunds, for every day of the range', function () {
    expect($this->reports->dailyRevenue(now()->subDays(2), now()))->toBe([
        ['date' => '2026-11-03', 'payments' => 0, 'refunds' => 0, 'net' => 0],
        ['date' => '2026-11-04', 'payments' => 10000, 'refunds' => 0, 'net' => 10000],
        ['date' => '2026-11-05', 'payments' => 30000, 'refunds' => 7000, 'net' => 23000],
    ]);
});

it('splits net revenue by channel and by train', function () {
    expect($this->reports->revenueBy('channel', now()->subDay(), now()))->toBe([
        ['label' => 'Counter', 'payments' => 30000, 'refunds' => 0, 'net' => 30000],
        ['label' => 'Online', 'payments' => 10000, 'refunds' => 7000, 'net' => 3000],
    ])->and($this->reports->revenueBy('train', now()->subDay(), now()))->toBe([
        ['label' => $this->train->label(), 'payments' => 40000, 'refunds' => 7000, 'net' => 33000],
    ])->and($this->reports->revenueBy('train', now(), now())[0]['net'])->toBe(23000);
});

it('gives ticket sales per class before refunds', function () {
    expect($this->reports->salesByClass(now()->subDay(), now()))->toBe([
        ['label' => SeatClass::ShovanChair->getLabel(), 'seats' => 4, 'sales' => 40000],
    ]);
});

it('counts occupancy like the seat map: sold seats out of the rake, cancelled seats not counted', function () {
    expect($this->reports->occupancy($this->run))->toBe(['seats' => 12, 'sold' => 3, 'percent' => 25.0])
        ->and($this->reports->occupancyByRun(CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-10')))->toBe([
            ['run_id' => $this->run->id, 'date' => '2026-11-10', 'train' => $this->train->label(), 'seats' => 12, 'sold' => 3, 'percent' => 25.0],
        ]);
});

it('sums refunds by reason', function () {
    expect($this->reports->refunds(now(), now()))->toBe([
        'rows' => [['label' => 'Passenger cancelled', 'count' => 1, 'amount' => 7000]],
        'total' => 7000,
        'open' => 0,
    ]);
});

it('counts tickets sold, including ones cancelled later', function () {
    expect($this->reports->ticketsSold(now(), now()))->toBe(['bookings' => 2, 'passengers' => 3])
        ->and($this->reports->ticketsSold(now()->subDay(), now()->subDay()))->toBe(['bookings' => 1, 'passengers' => 1]);
});

it('gives on-time % for runs that arrived', function () {
    foreach ([0, 5, 20] as $i => $delay) {
        TrainRun::factory()->create(['journey_date' => '2026-11-0'.($i + 1), 'status' => RunStatus::Arrived, 'delay_minutes' => $delay]);
    }
    TrainRun::factory()->create(['journey_date' => '2026-11-02', 'status' => RunStatus::Running, 'delay_minutes' => 60]); // not arrived

    expect($this->reports->onTime(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-03')))
        ->toBe(['arrived' => 3, 'on_time' => 2, 'percent' => 66.7, 'average_delay' => 8.3]);
});

it('refuses a range that ends before it starts', function () {
    expect(fn () => $this->reports->dailyRevenue(now(), now()->subDay()))->toThrow(InvalidArgumentException::class);
});
