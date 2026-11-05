<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Enums\SeatBookingStatus;
use App\Models\Booking;
use App\Models\RefundRule;
use App\Models\ScheduleStop;
use App\Models\Seat;
use App\Models\Train;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\FareService;
use App\Services\SeatInventoryService;
use App\Services\TicketService;
use App\Services\TrainRunService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample history for the dashboard and reports: about a month of sold, cancelled and
 * refunded bookings, plus actual running times, ending with some advance sales for the
 * next two days. Rows are written in bulk (the booking services take ~0.3 s a sale,
 * far too slow for thousands); fares come from FareService and refunds follow the
 * refund rules, so the numbers match what the app would have produced.
 *
 * Needs the demo network, coaches, fares, refund rules and users. Skipped if any
 * booking already exists on a past run. Random but repeatable (fixed seed).
 */
class PastBookingsSeeder extends Seeder
{
    /** Days of history before today. */
    public const int DAYS = 30;

    /** Days of history for this run (tests use fewer). */
    public int $days = self::DAYS;

    /** Days after today that already have some advance sales. */
    public const int AHEAD_DAYS = 2;

    /** Sample passengers who book online (created if missing). */
    public const int PASSENGERS = 12;

    private const int CHUNK = 500;

    /** Insert order, parents first. */
    private const array TABLES = ['bookings', 'seat_bookings', 'passengers', 'payments', 'refunds', 'run_stop_times'];

    /** @var array<string, list<array<string, mixed>>> table => rows */
    private array $rows = [];

    /** @var array<string, int> table => next id */
    private array $ids = [];

    /** @var array<string, true> */
    private array $pnrs = [];

    /** @var array<string, int> */
    private array $fares = [];

    /** @var Collection<int, RefundRule> */
    private Collection $refundRules;

    private CarbonImmutable $now;

    public function __construct(
        private readonly TrainRunService $runs,
        private readonly SeatInventoryService $inventory,
        private readonly FareService $fareService,
    ) {}

    public function run(): void
    {
        $this->now = CarbonImmutable::now();
        $start = $this->now->startOfDay()->subDays($this->days);

        $hasHistory = Booking::query()
            ->whereHas('trainRun', fn ($q) => $q->whereDate('journey_date', '<', $this->now->toDateString()))
            ->exists();

        if ($hasHistory) {
            $this->command?->warn('Past bookings already exist; skipped. Use "Reset demo data" to start again.');

            return;
        }

        $clerk = User::role(Role::CounterClerk->value)->first();
        $stationMaster = User::role(Role::StationMaster->value)->first();

        if ($clerk === null || $stationMaster === null) {
            throw new RuntimeException('Run DemoUsersSeeder first: a counter clerk and a station master are needed.');
        }

        mt_srand(2026);
        fake()->seed(2026);

        $passengers = $this->passengers();
        $this->pnrs = array_fill_keys(Booking::query()->pluck('pnr')->all(), true);
        $this->refundRules = RefundRule::query()->orderByDesc('hours_before')->get();
        DB::transaction(fn () => $this->runs->generate($start, $this->days + self::AHEAD_DAYS + 1));

        foreach (self::TABLES as $table) {
            $this->ids[$table] = (int) DB::table($table)->max('id') + 1;
        }

        $trains = Train::query()->with(['scheduleStops.routeStop', 'rakeCoaches.coachType.seats'])->get()->keyBy('id');
        $runs = TrainRun::query()
            ->whereDate('journey_date', '>=', $start->toDateString())
            ->whereDate('journey_date', '<=', $this->now->addDays(self::AHEAD_DAYS)->toDateString())
            ->where('status', RunStatus::Scheduled->value)
            ->orderBy('journey_date')
            ->get();

        $totals = array_fill_keys(self::TABLES, 0);
        $arrived = 0;

        DB::transaction(function () use ($runs, $trains, $passengers, $clerk, $stationMaster, &$totals, &$arrived) {
            foreach ($runs as $run) {
                $train = $trains->get($run->train_id);

                if ($train === null || $train->scheduleStops->count() < 2 || $train->rakeCoaches->isEmpty()) {
                    continue;
                }

                $run->setRelation('train', $train);
                $this->sellRun($run, $passengers, $clerk);
                $delay = $this->runTimes($run, $stationMaster);

                if ($delay !== null) {
                    $run->forceFill(['status' => RunStatus::Arrived, 'delay_minutes' => $delay])->saveQuietly();
                    $arrived++;
                }

                // Written run by run: a month of rows doesn't fit in memory at once.
                foreach (self::TABLES as $table) {
                    foreach (array_chunk($this->rows[$table] ?? [], self::CHUNK) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }

                    $totals[$table] += count($this->rows[$table] ?? []);
                }

                $this->rows = [];
            }
        });

        $this->command?->info(sprintf(
            'Past bookings: %d bookings (%d seats), %d refunds, %d runs with actual times.',
            $totals['bookings'], $totals['seat_bookings'], $totals['refunds'], $arrived,
        ));
    }

    /**
     * Fill the run to a random occupancy with counter and online sales; about one
     * booking in twelve is cancelled before the train leaves.
     *
     * @param  list<User>  $passengers
     */
    private function sellRun(TrainRun $run, array $passengers, User $clerk): void
    {
        /** @var list<ScheduleStop> $stops */
        $stops = $run->train->scheduleStops->values()->all();
        $departures = [];

        foreach ($stops as $stop) {
            $departures[$stop->routeStop->sequence] = $stop->departureOn($run->journey_date);
        }

        $sequences = array_keys($departures);
        $firstDeparture = $departures[$sequences[0]];

        if ($firstDeparture === null || $firstDeparture->subDays(3)->gt($this->now)) {
            return; // sales for this run haven't started yet
        }

        $seats = $this->seatPools($run);
        $toSell = (int) round((count($seats['open']) + count($seats['counter'])) * mt_rand(15, 65) / 100);

        // Upcoming runs are only partly sold so far.
        if ($firstDeparture->isAfter($this->now)) {
            $toSell = intdiv($toSell, 2);
        }

        while ($toSell > 0) {
            $online = mt_rand(1, 100) <= 55;
            $chosen = $this->takeSeats($seats, min($toSell, [1, 1, 1, 2, 2, 2, 3, 4][mt_rand(0, 7)]), $online);

            if ($chosen === []) {
                break;
            }

            $toSell -= count($chosen);
            [$from, $to] = $this->segment($sequences);
            $boarding = $departures[$from] ?? $firstDeparture;
            $soldAt = $this->saleTime($boarding);

            if ($soldAt !== null) {
                $this->book($run, $chosen, $from, $to, $boarding, $soldAt, $online ? $passengers[array_rand($passengers)] : null, $clerk);
            }
        }
    }

    /**
     * @param  list<array{coach_no: string, seat_id: int, class: string, women: bool}>  $seats
     */
    private function book(TrainRun $run, array $seats, int $from, int $to, CarbonImmutable $boarding, CarbonImmutable $soldAt, ?User $user, User $clerk): void
    {
        $bookingId = $this->ids['bookings']++;
        $women = in_array(true, array_column($seats, 'women'), true);
        $method = $user === null ? PaymentMethod::Cash : [PaymentMethod::Bkash, PaymentMethod::Bkash, PaymentMethod::Nagad, PaymentMethod::Card][mt_rand(0, 3)];
        $total = 0;

        // About one booking in twelve is cancelled, at least half an hour before boarding.
        $cancelAt = null;

        if (mt_rand(1, 12) === 1) {
            $window = (int) $soldAt->diffInMinutes($boarding) - 30;
            $at = $soldAt->addMinutes(mt_rand(10, max(10, $window)));
            $cancelAt = $at->lt($boarding->subMinutes(30)) && $at->lt($this->now) ? $at : null;
        }

        foreach ($seats as $seat) {
            $seatBookingId = $this->ids['seat_bookings']++;
            $fare = $this->fare($run, $seat, $from, $to);
            $total += $fare;

            $this->add('seat_bookings', [
                'id' => $seatBookingId,
                'booking_id' => $bookingId,
                'train_run_id' => $run->id,
                'coach_no' => $seat['coach_no'],
                'seat_id' => $seat['seat_id'],
                'from_seq' => $from,
                'to_seq' => $to,
                'status' => ($cancelAt === null ? SeatBookingStatus::Booked : SeatBookingStatus::Cancelled)->value,
                'hold_until' => null,
                'created_at' => $soldAt,
                'updated_at' => $cancelAt ?? $soldAt,
            ]);

            $this->add('passengers', [
                'booking_id' => $bookingId,
                'seat_booking_id' => $seatBookingId,
                ...$this->person($women),
                'concession_id' => null,
                'fare_poisha' => $fare,
                'created_at' => $soldAt,
                'updated_at' => $soldAt,
            ]);
        }

        $paidAt = $soldAt->addMinutes($user === null ? 0 : mt_rand(1, 6));
        $paymentId = $this->ids['payments']++;
        $refund = $cancelAt === null ? null : $this->refundFor($total, $cancelAt, $boarding);

        $this->add('bookings', [
            'id' => $bookingId,
            'pnr' => $this->pnr(),
            'user_id' => $user?->id,
            'sold_by' => $user === null ? $clerk->id : null,
            'train_run_id' => $run->id,
            'channel' => ($user === null ? Channel::Counter : Channel::Online)->value,
            'from_seq' => $from,
            'to_seq' => $to,
            'total_poisha' => $total,
            'status' => ($cancelAt === null ? BookingStatus::Confirmed : BookingStatus::Cancelled)->value,
            'hold_until' => null,
            'contact_phone' => '01'.mt_rand(3, 9).str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
            'contact_email' => $user?->email,
            'confirmed_at' => $paidAt,
            'cancelled_at' => $cancelAt,
            'cancelled_by' => $cancelAt !== null && $user === null ? $clerk->id : null,
            'created_at' => $soldAt,
            'updated_at' => $cancelAt ?? $paidAt,
        ]);

        $refunded = $refund['amount_poisha'] ?? 0;

        $this->add('payments', [
            'id' => $paymentId,
            'booking_id' => $bookingId,
            'method' => $method->value,
            'reference' => ($user === null ? 'CSH' : 'PAY').strtoupper((string) Str::ulid($soldAt)),
            'txn_id' => $user === null ? null : 'MOCK'.strtoupper(Str::random(12)),
            'amount_poisha' => $total,
            'refunded_poisha' => $refunded,
            'status' => (match (true) {
                $refunded === 0 => PaymentStatus::Success,
                $refunded >= $total => PaymentStatus::Refunded,
                default => PaymentStatus::PartiallyRefunded,
            })->value,
            'failure_reason' => null,
            'paid_at' => $paidAt,
            'received_by' => $user === null ? $clerk->id : null,
            'created_at' => $soldAt,
            'updated_at' => $cancelAt ?? $paidAt,
        ]);

        if ($refund !== null) {
            $this->add('refunds', [
                'id' => $this->ids['refunds']++,
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
                'reason' => RefundReason::PassengerCancelled->value,
                ...$refund,
                'status' => RefundStatus::Processed->value,
                'txn_id' => $user === null ? null : 'MOCKRF'.strtoupper(Str::random(10)),
                'failure_reason' => null,
                'requested_by' => $user === null ? $clerk->id : null,
                'processed_at' => $cancelAt,
                'created_at' => $cancelAt,
                'updated_at' => $cancelAt,
            ]);
        }
    }

    /**
     * The refund the rules give for cancelling at $at; null when nothing is refunded.
     * Same calculation as RefundService::quote().
     *
     * @return array{amount_poisha: int, fee_poisha: int, percent: int, hours_before: float}|null
     */
    private function refundFor(int $paid, CarbonImmutable $at, CarbonImmutable $boarding): ?array
    {
        $hours = max(0.0, $at->diffInSeconds($boarding, false) / 3600);
        $rule = $this->refundRules->first(fn (RefundRule $r) => $r->hours_before <= $hours);

        if ($rule === null || $paid === 0) {
            return null;
        }

        $gross = intdiv($paid * $rule->percent, 100);
        $fee = min($rule->fee_poisha, $gross);

        return $gross - $fee > 0
            ? ['amount_poisha' => $gross - $fee, 'fee_poisha' => $fee, 'percent' => $rule->percent, 'hours_before' => round($hours, 2)]
            : null;
    }

    /**
     * Departure from the origin and arrival at the terminus for a run that has finished:
     * most leave on time and some arrive late. Returns the arrival delay, or null if the
     * run hasn't finished yet.
     */
    private function runTimes(TrainRun $run, User $stationMaster): ?int
    {
        $first = $run->train->scheduleStops->first();
        $last = $run->train->scheduleStops->last();
        $departure = $first?->departureOn($run->journey_date);
        $arrival = $last?->arrivalOn($run->journey_date);

        if ($first === null || $last === null || $departure === null || $arrival === null) {
            return null;
        }

        $leftLate = mt_rand(1, 100) <= 85 ? mt_rand(0, 3) : mt_rand(5, 25);
        $arrivedLate = max(0, $leftLate + (mt_rand(1, 100) <= 75 ? mt_rand(-5, 2) : mt_rand(5, 50)));
        $actualDeparture = $departure->addMinutes($leftLate);
        $actualArrival = $arrival->addMinutes($arrivedLate);

        if ($actualArrival->addMinutes(10)->gt($this->now)) {
            return null;
        }

        foreach ([[$first, null, $actualDeparture, $leftLate], [$last, $actualArrival, null, $arrivedLate]] as [$stop, $arr, $dep, $delay]) {
            $this->add('run_stop_times', [
                'id' => $this->ids['run_stop_times']++,
                'train_run_id' => $run->id,
                'schedule_stop_id' => $stop->id,
                'actual_arrival' => $arr,
                'actual_departure' => $dep,
                'delay_minutes' => $delay,
                'recorded_by' => $stationMaster->id,
                'created_at' => $arr ?? $dep,
                'updated_at' => $arr ?? $dep,
            ]);
        }

        return $arrivedLate;
    }

    /**
     * Seats of the rake, whole coaches in shuffled order so coaches fill unevenly.
     * Counter quota seats are kept apart: online sales can't use them.
     *
     * @return array{open: list<array{coach_no: string, seat_id: int, class: string, women: bool}>, counter: list<array{coach_no: string, seat_id: int, class: string, women: bool}>}
     */
    private function seatPools(TrainRun $run): array
    {
        $coaches = $run->train->rakeCoaches->all();
        shuffle($coaches); // uses the mt_srand() seed
        $pools = ['open' => [], 'counter' => []];

        foreach ($coaches as $coach) {
            $quota = array_flip($this->inventory->counterQuotaSeatIds($coach));

            foreach ($coach->coachType->seats as $seat) {
                $pools[isset($quota[$seat->id]) ? 'counter' : 'open'][] = [
                    'coach_no' => $coach->coach_no,
                    'seat_id' => $seat->id,
                    'class' => $coach->coachType->seat_class->value,
                    'women' => $seat->hasFlag(Seat::FLAG_WOMEN),
                ];
            }
        }

        return $pools;
    }

    /**
     * Seats next to each other in one coach. Counter sales use either pool; online only open seats.
     *
     * @param  array{open: list<array{coach_no: string, seat_id: int, class: string, women: bool}>, counter: list<array{coach_no: string, seat_id: int, class: string, women: bool}>}  $pools
     * @return list<array{coach_no: string, seat_id: int, class: string, women: bool}>
     */
    private function takeSeats(array &$pools, int $count, bool $online): array
    {
        $order = $online ? ['open'] : (mt_rand(0, 2) === 0 ? ['counter', 'open'] : ['open', 'counter']);

        foreach ($order as $pool) {
            if ($pools[$pool] === []) {
                continue;
            }

            $coach = $pools[$pool][0]['coach_no'];
            $chosen = [];

            while (count($chosen) < $count && $pools[$pool] !== [] && $pools[$pool][0]['coach_no'] === $coach) {
                $chosen[] = array_shift($pools[$pool]);
            }

            return $chosen;
        }

        return [];
    }

    /**
     * Mostly the whole journey; sometimes a shorter trip between two calling stations.
     *
     * @param  list<int>  $sequences
     * @return array{0: int, 1: int}
     */
    private function segment(array $sequences): array
    {
        $n = count($sequences);

        if ($n <= 2 || mt_rand(1, 100) <= 60) {
            return [$sequences[0], $sequences[$n - 1]];
        }

        $from = mt_rand(0, $n - 2);

        return [$sequences[$from], $sequences[mt_rand($from + 1, $n - 1)]];
    }

    /**
     * When a ticket for this departure was sold: up to three days before, between 07:00
     * and 22:00, never in the future. Null if that leaves no time before departure.
     */
    private function saleTime(CarbonImmutable $departure): ?CarbonImmutable
    {
        $at = $departure->subMinutes(mt_rand(60, 3 * 24 * 60));

        if ($at->hour < 7) {
            $at = $at->setTime(mt_rand(7, 10), mt_rand(0, 59));
        } elseif ($at->hour >= 22) {
            $at = $at->setTime(mt_rand(19, 21), mt_rand(0, 59));
        }

        if ($at->gte($this->now)) {
            $at = $this->now->subMinutes(mt_rand(5, 180));
        }

        return $at->lt($departure->subMinutes(30)) ? $at : null;
    }

    /**
     * @param  array{coach_no: string, seat_id: int, class: string, women: bool}  $seat
     */
    private function fare(TrainRun $run, array $seat, int $from, int $to): int
    {
        $key = "{$run->train_id}|{$seat['class']}|{$from}|{$to}";

        return $this->fares[$key] ??= $this->fareService
            ->quote($run, [['coach_no' => $seat['coach_no'], 'seat_id' => $seat['seat_id']]], $from, $to)
            ->forSeat($seat['coach_no'], $seat['seat_id']);
    }

    /**
     * @return array{name: string, age: int, gender: string, nid: string|null}
     */
    private function person(bool $woman): array
    {
        // Women-only seats are booked by all-women parties, so every seat order is valid.
        $gender = $woman ? 'female' : ['male', 'male', 'female'][mt_rand(0, 2)];
        $age = mt_rand(1, 100) <= 12 ? mt_rand(4, 17) : mt_rand(18, 75);

        return [
            'name' => fake()->firstName($gender).' '.fake()->lastName(),
            'age' => $age,
            'gender' => $gender,
            'nid' => $age >= 18 ? (string) mt_rand(1000000000, 9999999999) : null,
        ];
    }

    private function pnr(): string
    {
        do {
            $pnr = '';

            for ($i = 0; $i < TicketService::PNR_LENGTH; $i++) {
                $pnr .= TicketService::PNR_ALPHABET[mt_rand(0, strlen(TicketService::PNR_ALPHABET) - 1)];
            }
        } while (isset($this->pnrs[$pnr]));

        $this->pnrs[$pnr] = true;

        return $pnr;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function add(string $table, array $row): void
    {
        $this->rows[$table][] = array_map(
            fn ($v) => $v instanceof CarbonImmutable ? $v->toDateTimeString() : $v,
            $row,
        );
    }

    /**
     * @return list<User>
     */
    private function passengers(): array
    {
        $domain = config('railway.demo_email_domain');
        $users = [];

        for ($i = 1; $i <= self::PASSENGERS; $i++) {
            $user = User::firstOrCreate(
                ['email' => "passenger{$i}@{$domain}"],
                ['name' => fake()->name(), 'password' => Str::password(16), 'email_verified_at' => now()],
            );
            $user->syncRoles([Role::Passenger->value]);
            $users[] = $user;
        }

        return $users;
    }
}
