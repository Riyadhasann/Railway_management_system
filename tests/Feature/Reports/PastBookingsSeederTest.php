<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\PaymentStatus;
use App\Enums\RunStatus;
use App\Enums\SeatBookingStatus;
use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\RakeCoach;
use App\Models\Refund;
use App\Models\Seat;
use App\Models\SeatBooking;
use App\Models\TrainRun;
use App\Services\ReportService;
use App\Services\SeatInventoryService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoCoachesSeeder;
use Database\Seeders\DemoFaresSeeder;
use Database\Seeders\DemoNetworkSeeder;
use Database\Seeders\DemoRefundRulesSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\PastBookingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['railway.demo_password' => 'demo-pass-123']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));

    $this->seed([
        RolesAndPermissionsSeeder::class, DemoUsersSeeder::class, DemoNetworkSeeder::class,
        DemoCoachesSeeder::class, DemoFaresSeeder::class, DemoRefundRulesSeeder::class,
    ]);

    $seeder = app(PastBookingsSeeder::class);
    $seeder->days = 3;
    $seeder->run();
});

it('sells, cancels and refunds on past runs and records how late they were', function () {
    $past = Booking::whereHas('trainRun', fn ($q) => $q->whereDate('journey_date', '<', '2026-11-05'));

    expect($past->count())->toBeGreaterThan(100)
        ->and(Booking::where('channel', Channel::Online)->count())->toBeGreaterThan(0)
        ->and(Booking::where('channel', Channel::Counter)->count())->toBeGreaterThan(0)
        ->and(Booking::where('status', BookingStatus::Cancelled)->count())->toBeGreaterThan(0)
        ->and(Booking::whereNotIn('status', [BookingStatus::Confirmed->value, BookingStatus::Cancelled->value])->count())->toBe(0)
        ->and(Booking::where('created_at', '>', now())->count())->toBe(0);

    // Runs that have finished are arrived, with times; later ones aren't.
    $arrived = TrainRun::where('status', RunStatus::Arrived)->get();
    expect($arrived)->not->toBeEmpty()
        ->and($arrived->every(fn (TrainRun $r) => $r->stopTimes()->count() === 2))->toBeTrue()
        ->and(TrainRun::whereDate('journey_date', '>', '2026-11-05')->where('status', '!=', RunStatus::Scheduled)->count())->toBe(0);

    $reports = app(ReportService::class);
    $days = $reports->dailyRevenue(now()->subDays(3), now());
    expect(collect($days)->every(fn (array $d) => $d['payments'] > 0))->toBeTrue()
        ->and($reports->onTime(now()->subDays(3), now())['arrived'])->toBe($arrived->count());
});

it('keeps the money consistent: passenger fares, payments and refunds add up', function () {
    Booking::with(['passengers', 'payments', 'refunds'])->each(function (Booking $b) {
        expect($b->passengers->sum('fare_poisha'))->toBe($b->total_poisha)
            ->and($b->payments)->toHaveCount(1)
            ->and($b->payments->first()->amount_poisha)->toBe($b->total_poisha)
            ->and($b->payments->first()->refunded_poisha)->toBe((int) $b->refunds->sum('amount_poisha'));

        if ($b->status === BookingStatus::Confirmed) {
            expect($b->refunds)->toBeEmpty()->and($b->payments->first()->status)->toBe(PaymentStatus::Success);
        }
    });

    expect(Refund::where('amount_poisha', '>', 0)->count())->toBe(Refund::count())
        ->and(Refund::whereColumn('processed_at', '<', 'created_at')->count())->toBe(0)
        ->and(Payment::whereNull('paid_at')->count())->toBe(0);
});

it('follows the seat rules: no double sales, quota seats only at the counter, women-only seats for women', function () {
    $booked = SeatBooking::where('status', SeatBookingStatus::Booked)->get();
    $clashes = $booked->groupBy(fn (SeatBooking $s) => "{$s->train_run_id}|{$s->coach_no}|{$s->seat_id}")
        ->filter(fn ($group) => $group->count() > 1);
    expect($booked)->not->toBeEmpty()->and($clashes)->toBeEmpty();

    $inventory = app(SeatInventoryService::class);
    $quota = RakeCoach::with('coachType.seats')->get()
        ->flatMap(fn (RakeCoach $c) => array_map(fn ($id) => "{$c->train_id}|{$c->coach_no}|{$id}", $inventory->counterQuotaSeatIds($c)))
        ->flip();
    $online = SeatBooking::with('booking.trainRun')->whereHas('booking', fn ($q) => $q->where('channel', Channel::Online))->get();
    expect($online->filter(fn (SeatBooking $s) => $quota->has("{$s->booking->trainRun->train_id}|{$s->coach_no}|{$s->seat_id}")))->toBeEmpty();

    $women = Passenger::with('seatBooking.seat')->get()->filter(fn (Passenger $p) => $p->seatBooking->seat->hasFlag(Seat::FLAG_WOMEN));
    expect($women->every(fn (Passenger $p) => $p->gender->value === 'female'))->toBeTrue();

    // The real booking engine sees the seeded seats as sold.
    $sold = SeatBooking::with('trainRun')->where('status', SeatBookingStatus::Booked)
        ->whereHas('trainRun', fn ($q) => $q->whereDate('journey_date', '>', '2026-11-05'))->firstOrFail();
    expect(fn () => $inventory->hold($sold->trainRun, [['coach_no' => $sold->coach_no, 'seat_id' => $sold->seat_id]], $sold->from_seq, $sold->to_seq, Channel::Counter))
        ->toThrow(SeatUnavailableException::class);
});

it('is skipped when history already exists', function () {
    $count = Booking::count();

    app(PastBookingsSeeder::class)->run();

    expect(Booking::count())->toBe($count);
});
