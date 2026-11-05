<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\RunStatus;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatClass;
use App\Models\Train;
use App\Models\TrainRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Revenue and operations figures for the dashboard and exports. All money is poisha.
 *
 * Revenue is cash basis: money received (payments by paid_at) minus money returned
 * (processed refunds by processed_at), so "today's revenue" is today's successful
 * payments minus today's refunds. Reports query the main database (prototype).
 */
class ReportService
{
    /** A run that arrives this many minutes late or less counts as on time. */
    public const int ON_TIME_MINUTES = 5;

    /**
     * Money in, money out and net per day, every day of the range included.
     *
     * @return list<array{date: string, payments: int, refunds: int, net: int}>
     */
    public function dailyRevenue(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $payments = DB::table('payments')
            ->whereIn('status', PaymentStatus::captured())
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->selectRaw('DATE(paid_at) as day, SUM(amount_poisha) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $refunds = DB::table('refunds')
            ->where('status', RefundStatus::Processed->value)
            ->whereDate('processed_at', '>=', $from->toDateString())
            ->whereDate('processed_at', '<=', $to->toDateString())
            ->selectRaw('DATE(processed_at) as day, SUM(amount_poisha) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $in = (int) ($payments[$key] ?? 0);
            $out = (int) ($refunds[$key] ?? 0);
            $days[] = ['date' => $key, 'payments' => $in, 'refunds' => $out, 'net' => $in - $out];
        }

        return $days;
    }

    /**
     * Net revenue (payments minus refunds in the range) per train or per sales channel.
     *
     * @param  'train'|'channel'  $by
     * @return list<array{label: string, payments: int, refunds: int, net: int}>
     */
    public function revenueBy(string $by, CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $key = $by === 'train' ? 'train_runs.train_id' : 'bookings.channel';

        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('train_runs', 'train_runs.id', '=', 'bookings.train_run_id')
            ->whereIn('payments.status', PaymentStatus::captured())
            ->whereNotNull('payments.paid_at')
            ->whereDate('payments.paid_at', '>=', $from->toDateString())
            ->whereDate('payments.paid_at', '<=', $to->toDateString())
            ->selectRaw("{$key} as k, SUM(payments.amount_poisha) as total")
            ->groupBy('k')
            ->pluck('total', 'k');

        $refunds = DB::table('refunds')
            ->join('bookings', 'bookings.id', '=', 'refunds.booking_id')
            ->join('train_runs', 'train_runs.id', '=', 'bookings.train_run_id')
            ->where('refunds.status', RefundStatus::Processed->value)
            ->whereDate('refunds.processed_at', '>=', $from->toDateString())
            ->whereDate('refunds.processed_at', '<=', $to->toDateString())
            ->selectRaw("{$key} as k, SUM(refunds.amount_poisha) as total")
            ->groupBy('k')
            ->pluck('total', 'k');

        $labels = $by === 'train'
            ? Train::query()->whereIn('id', $payments->keys()->merge($refunds->keys())->unique())->get()->mapWithKeys(fn (Train $t) => [$t->id => $t->label()])
            : collect(Channel::cases())->mapWithKeys(fn (Channel $c) => [$c->value => $c->getLabel()]);

        $rows = [];

        foreach ($payments->keys()->merge($refunds->keys())->unique() as $k) {
            $in = (int) ($payments[$k] ?? 0);
            $out = (int) ($refunds[$k] ?? 0);
            $rows[] = ['label' => (string) ($labels[$k] ?? $k), 'payments' => $in, 'refunds' => $out, 'net' => $in - $out];
        }

        usort($rows, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return $rows;
    }

    /**
     * Ticket sales per seat class: passenger fares of bookings paid in the range,
     * before refunds (a refund is for a whole booking, not one class).
     *
     * @return list<array{label: string, seats: int, sales: int}>
     */
    public function salesByClass(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $paidBookings = DB::table('payments')
            ->whereIn('status', PaymentStatus::captured())
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->select('booking_id');

        $rows = DB::table('passengers')
            ->join('seat_bookings', 'seat_bookings.id', '=', 'passengers.seat_booking_id')
            ->join('bookings', 'bookings.id', '=', 'passengers.booking_id')
            ->join('train_runs', 'train_runs.id', '=', 'bookings.train_run_id')
            ->join('rake_coaches', fn ($j) => $j->on('rake_coaches.train_id', '=', 'train_runs.train_id')->on('rake_coaches.coach_no', '=', 'seat_bookings.coach_no'))
            ->join('coach_types', 'coach_types.id', '=', 'rake_coaches.coach_type_id')
            ->whereIn('passengers.booking_id', $paidBookings)
            ->selectRaw('coach_types.seat_class as k, COUNT(*) as seats, SUM(passengers.fare_poisha) as sales')
            ->groupBy('k')
            ->get();

        return array_values($rows
            ->map(fn ($r) => [
                'label' => SeatClass::tryFrom((string) $r->k)?->getLabel() ?? (string) $r->k,
                'seats' => (int) $r->seats,
                'sales' => (int) $r->sales,
            ])
            ->sortByDesc('sales')
            ->values()
            ->all());
    }

    /**
     * Seats sold on one run: a seat counts once if any segment of it is booked,
     * as on the full-journey seat map.
     *
     * @return array{seats: int, sold: int, percent: float}
     */
    public function occupancy(TrainRun $run): array
    {
        $seats = $this->seatsPerTrain([$run->train_id])[$run->train_id] ?? 0;
        $sold = $this->soldPerRun([$run->id])[$run->id] ?? 0;

        return ['seats' => $seats, 'sold' => $sold, 'percent' => $this->percent($sold, $seats)];
    }

    /**
     * Occupancy of every run in the range that was not cancelled, by date then train.
     *
     * @return list<array{run_id: int, date: string, train: string, seats: int, sold: int, percent: float}>
     */
    public function occupancyByRun(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $runs = TrainRun::query()
            ->whereDate('journey_date', '>=', $from->toDateString())
            ->whereDate('journey_date', '<=', $to->toDateString())
            ->where('status', '!=', RunStatus::Cancelled->value)
            ->with('train')
            ->get()
            ->sortBy(fn (TrainRun $r) => [$r->journey_date->toDateString(), $r->train->number])
            ->values();

        $seats = $this->seatsPerTrain(array_values(array_unique(array_map(fn (TrainRun $r) => $r->train_id, $runs->all()))));
        $sold = $this->soldPerRun(array_values(array_map(fn (TrainRun $r) => $r->id, $runs->all())));

        return array_values($runs->map(fn (TrainRun $r) => [
            'run_id' => $r->id,
            'date' => $r->journey_date->toDateString(),
            'train' => $r->train->label(),
            'seats' => $seats[$r->train_id] ?? 0,
            'sold' => $sold[$r->id] ?? 0,
            'percent' => $this->percent($sold[$r->id] ?? 0, $seats[$r->train_id] ?? 0),
        ])->all());
    }

    /**
     * Refunds processed in the range by reason, plus refunds still waiting or failed.
     *
     * @return array{rows: list<array{label: string, count: int, amount: int}>, total: int, open: int}
     */
    public function refunds(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $rows = DB::table('refunds')
            ->where('status', RefundStatus::Processed->value)
            ->whereDate('processed_at', '>=', $from->toDateString())
            ->whereDate('processed_at', '<=', $to->toDateString())
            ->selectRaw('reason, COUNT(*) as n, SUM(amount_poisha) as amount')
            ->groupBy('reason')
            ->get()
            ->map(fn ($r) => [
                'label' => RefundReason::tryFrom((string) $r->reason)?->getLabel() ?? (string) $r->reason,
                'count' => (int) $r->n,
                'amount' => (int) $r->amount,
            ])
            ->sortByDesc('amount')
            ->values();

        return [
            'rows' => array_values($rows->all()),
            'total' => (int) $rows->sum('amount'),
            'open' => DB::table('refunds')->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value])->count(),
        ];
    }

    /**
     * Punctuality of runs that arrived, by journey date in the range.
     *
     * @return array{arrived: int, on_time: int, percent: float, average_delay: float}
     */
    public function onTime(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $delays = TrainRun::query()
            ->where('status', RunStatus::Arrived->value)
            ->whereDate('journey_date', '>=', $from->toDateString())
            ->whereDate('journey_date', '<=', $to->toDateString())
            ->pluck('delay_minutes');

        $onTime = $delays->filter(fn ($d) => (int) $d <= self::ON_TIME_MINUTES)->count();

        return [
            'arrived' => $delays->count(),
            'on_time' => $onTime,
            'percent' => $this->percent($onTime, $delays->count()),
            'average_delay' => round((float) ($delays->avg() ?? 0), 1),
        ];
    }

    /**
     * Confirmed bookings sold in the range (for the dashboard's headline numbers).
     *
     * @return array{bookings: int, passengers: int}
     */
    public function ticketsSold(CarbonInterface $from, CarbonInterface $to): array
    {
        [$from, $to] = $this->range($from, $to);

        $bookings = DB::table('bookings')
            ->whereNotNull('confirmed_at')
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value, BookingStatus::Cancelled->value])
            ->whereDate('confirmed_at', '>=', $from->toDateString())
            ->whereDate('confirmed_at', '<=', $to->toDateString());

        return [
            'bookings' => (clone $bookings)->count(),
            'passengers' => DB::table('passengers')->whereIn('booking_id', $bookings->select('id'))->count(),
        ];
    }

    /**
     * @param  list<int>  $trainIds
     * @return array<int, int> train id => seats in its rake
     */
    private function seatsPerTrain(array $trainIds): array
    {
        if ($trainIds === []) {
            return [];
        }

        return DB::table('rake_coaches')
            ->join('seats', 'seats.coach_type_id', '=', 'rake_coaches.coach_type_id')
            ->whereIn('rake_coaches.train_id', $trainIds)
            ->selectRaw('rake_coaches.train_id as k, COUNT(*) as n')
            ->groupBy('k')
            ->pluck('n', 'k')
            ->mapWithKeys(fn ($n, $k) => [(int) $k => (int) $n])
            ->all();
    }

    /**
     * @param  list<int>  $runIds
     * @return array<int, int> run id => distinct seats with a booked segment
     */
    private function soldPerRun(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $seats = DB::table('seat_bookings')
            ->whereIn('train_run_id', $runIds)
            ->where('status', SeatBookingStatus::Booked->value)
            ->select('train_run_id', 'coach_no', 'seat_id')
            ->distinct();

        return DB::query()
            ->fromSub($seats, 's')
            ->selectRaw('train_run_id as k, COUNT(*) as n')
            ->groupBy('k')
            ->pluck('n', 'k')
            ->mapWithKeys(fn ($n, $k) => [(int) $k => (int) $n])
            ->all();
    }

    private function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part * 100 / $whole, 1);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        if ($from->gt($to)) {
            throw new InvalidArgumentException('The report must end on or after its start date.');
        }

        return [$from, $to];
    }
}
