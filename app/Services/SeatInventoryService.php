<?php

namespace App\Services;

use App\Data\CoachMap;
use App\Data\SeatCell;
use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatState;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\RakeCoach;
use App\Models\ScheduleStop;
use App\Models\Seat;
use App\Models\SeatBooking;
use App\Models\TrainRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single entry point for a train run's seat inventory.
 *
 * Rules (docs/railway-erp-architecture.md §2.7):
 *  - a physical seat is (train_run_id, coach_no, seat_id);
 *  - two segments clash when existing.from < new.to AND existing.to > new.from;
 *  - taken = booked, blocked, or held with hold_until in the future;
 *  - every write locks the train_run row first, so writes to one run are serialised;
 *  - the last counter_quota seats of a coach (layout order) are sold only at the counter.
 */
class SeatInventoryService
{
    public function __construct(
        private readonly FareService $fares,
        private readonly TicketService $tickets,
    ) {}

    /**
     * The whole journey of a run: first to last stop the train calls at.
     *
     * @return array{0: int, 1: int}
     */
    public function fullJourney(TrainRun $run): array
    {
        $sequences = $run->train->scheduleStops()->with('routeStop')->get()
            ->map(fn ($stop) => $stop->routeStop->sequence);

        if ($sequences->count() < 2) {
            throw new InvalidArgumentException("Train {$run->train->number} has no timetable.");
        }

        return [(int) $sequences->min(), (int) $sequences->max()];
    }

    /**
     * Seat map of every coach for the segment from -> to.
     *
     * @return list<CoachMap>
     */
    public function availability(TrainRun $run, int $fromSeq, int $toSeq, ?Channel $channel = null): array
    {
        $this->assertSegment($fromSeq, $toSeq);

        $states = $this->takenStates($run, $fromSeq, $toSeq);

        // Online buyers can't take counter-quota seats: show the free ones as blocked.
        if ($channel === Channel::Online) {
            foreach ($run->train->rakeCoaches()->with('coachType.seats')->get() as $coach) {
                foreach ($this->counterQuotaSeatIds($coach) as $seatId) {
                    $states["{$coach->coach_no}|{$seatId}"] ??= SeatState::Blocked;
                }
            }
        }

        return array_values($run->train->rakeCoaches()->with('coachType.seats')->get()
            ->map(fn (RakeCoach $coach) => new CoachMap(
                coachNo: $coach->coach_no,
                coachTypeName: $coach->coachType->name,
                seatClass: $coach->coachType->seat_class,
                rows: $coach->coachType->layout_rows,
                cols: $coach->coachType->layout_cols,
                seats: array_values($coach->coachType->seats->map(fn (Seat $seat) => new SeatCell(
                    seatId: $seat->id,
                    seatNo: $seat->seat_no,
                    row: $seat->row,
                    col: $seat->col,
                    berth: $seat->berth,
                    flags: $seat->flags ?? [],
                    state: $states["{$coach->coach_no}|{$seat->id}"] ?? SeatState::Available,
                ))->all()),
            ))
            ->all());
    }

    /**
     * Hold seats for a new booking while it is paid for (online) or completed (counter).
     *
     * @param  array<int, array{coach_no: string, seat_id: int|string}>  $seats
     *
     * @throws SeatUnavailableException if any seat is taken on an overlapping segment
     * @throws BookingRefusedException if the request breaks a booking rule
     */
    public function hold(TrainRun $run, array $seats, int $fromSeq, int $toSeq, Channel $channel, ?User $user = null, ?User $soldBy = null): Booking
    {
        $this->assertSegment($fromSeq, $toSeq);
        $seats = $this->normaliseSeats($seats);

        return DB::transaction(function () use ($run, $seats, $fromSeq, $toSeq, $channel, $user, $soldBy) {
            // A second request for the same run waits here until this one commits.
            $this->lock($run);
            $run->refresh();

            $this->assertBookable($run, $fromSeq, $toSeq);
            $this->assertSeatsSellable($run, $seats, $channel);

            $taken = SeatBooking::query()
                ->where('train_run_id', $run->id)
                ->where(function ($q) use ($seats) {
                    foreach ($seats as $s) {
                        $q->orWhere(fn ($q) => $q->where('coach_no', $s['coach_no'])->where('seat_id', $s['seat_id']));
                    }
                })
                ->overlapping($fromSeq, $toSeq)
                ->taken()
                ->with('seat')
                ->get();

            if ($taken->isNotEmpty()) {
                throw SeatUnavailableException::for(array_values(
                    $taken->map(fn (SeatBooking $b) => "{$b->coach_no}-{$b->seat->seat_no}")->unique()->all()
                ));
            }

            $holdUntil = now()->addMinutes((int) config('railway.hold_minutes'));
            $quote = $this->fares->quote($run, $seats, $fromSeq, $toSeq);

            $booking = Booking::create([
                'pnr' => $this->tickets->newPnr(),
                'user_id' => $user?->id,
                'sold_by' => $soldBy?->id,
                'train_run_id' => $run->id,
                'channel' => $channel,
                'from_seq' => $fromSeq,
                'to_seq' => $toSeq,
                'total_poisha' => $quote->total(),
                'status' => BookingStatus::PendingPayment,
                'hold_until' => $holdUntil,
            ]);

            foreach ($seats as $s) {
                $booking->seatBookings()->create([
                    'train_run_id' => $run->id,
                    'coach_no' => $s['coach_no'],
                    'seat_id' => $s['seat_id'],
                    'from_seq' => $fromSeq,
                    'to_seq' => $toSeq,
                    'status' => SeatBookingStatus::Held,
                    'hold_until' => $holdUntil,
                ]);
            }

            return $booking;
        });
    }

    /**
     * Turn a held booking into a sale: booking confirmed, seats booked.
     * A hold that has run out still succeeds if nobody has taken its seats since
     * (late payment rule); otherwise the booking expires and HoldExpiredException is thrown.
     *
     * @throws HoldExpiredException
     */
    public function confirm(Booking $booking): Booking
    {
        // Expiring the booking must be committed, so the exception is thrown after the transaction.
        $confirmed = DB::transaction(function () use ($booking) {
            $this->lock($booking->trainRun);
            $booking->refresh();

            if ($booking->status === BookingStatus::Confirmed) {
                return true; // idempotent
            }

            if ($booking->status !== BookingStatus::PendingPayment) {
                throw new BookingRefusedException("Booking {$booking->pnr} is {$booking->status->getLabel()} and can not be confirmed.");
            }

            if (! $booking->isHeld() && $this->takenByOthers($booking)) {
                $this->markReleased($booking, BookingStatus::Expired);

                return false;
            }

            $booking->seatBookings()->update([
                'status' => SeatBookingStatus::Booked->value,
                'hold_until' => null,
            ]);
            $booking->update([
                'status' => BookingStatus::Confirmed,
                'hold_until' => null,
                'confirmed_at' => now(),
            ]);

            return true;
        });

        if (! $confirmed) {
            throw HoldExpiredException::for($booking->pnr);
        }

        return $booking;
    }

    /**
     * Give up an unpaid hold: seats go back on sale.
     */
    public function release(Booking $booking, BookingStatus $as = BookingStatus::Cancelled): void
    {
        DB::transaction(function () use ($booking, $as) {
            $this->lock($booking->trainRun);
            $booking->refresh();

            if ($booking->status === BookingStatus::PendingPayment) {
                $this->markReleased($booking, $as);
            }
        });
    }

    /**
     * Expire every unpaid hold whose time has run out (holds:release, every minute).
     * Expired holds already stop blocking seats the moment they run out; this tidies up.
     *
     * @return int number of bookings expired
     */
    public function releaseExpired(): int
    {
        $expired = 0;

        Booking::query()
            ->where('status', BookingStatus::PendingPayment->value)
            ->where('hold_until', '<=', now())
            ->with('trainRun')
            ->each(function (Booking $booking) use (&$expired) {
                $this->release($booking, BookingStatus::Expired);
                $expired += $booking->status === BookingStatus::Expired ? 1 : 0;
            });

        return $expired;
    }

    /**
     * Seats kept for counter sales: the last counter_quota seats in layout order.
     *
     * @return list<int>
     */
    public function counterQuotaSeatIds(RakeCoach $coach): array
    {
        if ($coach->counter_quota <= 0) {
            return [];
        }

        $ids = array_map('intval', $coach->coachType->seats->modelKeys());

        return array_slice($ids, -$coach->counter_quota);
    }

    /**
     * When the train leaves the boarding stop on this run.
     */
    public function boardingDeparture(TrainRun $run, int $fromSeq): ?CarbonImmutable
    {
        $stop = $run->train->scheduleStops()->with('routeStop')->get()
            ->first(fn (ScheduleStop $s) => $s->routeStop->sequence === $fromSeq);

        return $stop?->departureOn($run->journey_date);
    }

    /**
     * Admin seat blocking: takes the seats out of sale for the segment.
     *
     * @param  list<int>  $seatIds
     * @return int number of seats blocked
     *
     * @throws SeatUnavailableException if any seat is already taken on an overlapping segment
     */
    public function block(TrainRun $run, string $coachNo, array $seatIds, int $fromSeq, int $toSeq, User $by, ?string $reason = null): int
    {
        $this->assertSegment($fromSeq, $toSeq);

        return DB::transaction(function () use ($run, $coachNo, $seatIds, $fromSeq, $toSeq, $by, $reason) {
            $this->lock($run);
            $seats = $this->seatsInCoach($run, $coachNo, $seatIds);

            $taken = SeatBooking::query()
                ->where('train_run_id', $run->id)
                ->where('coach_no', $coachNo)
                ->whereIn('seat_id', $seats->modelKeys())
                ->overlapping($fromSeq, $toSeq)
                ->taken()
                ->pluck('seat_id')
                ->unique();

            if ($taken->isNotEmpty()) {
                throw SeatUnavailableException::for(
                    array_values($seats->whereIn('id', $taken)->map(fn (Seat $s) => "{$coachNo}-{$s->seat_no}")->all())
                );
            }

            foreach ($seats as $seat) {
                SeatBooking::create([
                    'train_run_id' => $run->id,
                    'coach_no' => $coachNo,
                    'seat_id' => $seat->id,
                    'from_seq' => $fromSeq,
                    'to_seq' => $toSeq,
                    'status' => SeatBookingStatus::Blocked,
                    'blocked_by' => $by->id,
                    'reason' => $reason,
                ]);
            }

            return $seats->count();
        });
    }

    /**
     * Release admin blocks on these seats (any segment).
     *
     * @param  list<int>  $seatIds
     * @return int number of block rows released
     */
    public function unblock(TrainRun $run, string $coachNo, array $seatIds): int
    {
        return DB::transaction(function () use ($run, $coachNo, $seatIds) {
            $this->lock($run);

            return SeatBooking::query()
                ->where('train_run_id', $run->id)
                ->where('coach_no', $coachNo)
                ->whereIn('seat_id', $seatIds)
                ->where('status', SeatBookingStatus::Blocked->value)
                ->update(['status' => SeatBookingStatus::Cancelled->value]);
        });
    }

    /**
     * Serialise all inventory writes for one run.
     */
    public function lock(TrainRun $run): void
    {
        TrainRun::whereKey($run->id)->lockForUpdate()->first();
    }

    /**
     * Taken seats for the segment, keyed "coach_no|seat_id". When several rows
     * overlap, booked wins over blocked, and blocked over held.
     *
     * @return array<string, SeatState>
     */
    private function takenStates(TrainRun $run, int $fromSeq, int $toSeq): array
    {
        $rank = [SeatState::Held->value => 1, SeatState::Blocked->value => 2, SeatState::Booked->value => 3];
        $states = [];

        $rows = SeatBooking::query()
            ->where('train_run_id', $run->id)
            ->overlapping($fromSeq, $toSeq)
            ->taken()
            ->get(['coach_no', 'seat_id', 'status']);

        foreach ($rows as $row) {
            $state = SeatState::from($row->status->value);
            $key = "{$row->coach_no}|{$row->seat_id}";

            if (! isset($states[$key]) || $rank[$state->value] > $rank[$states[$key]->value]) {
                $states[$key] = $state;
            }
        }

        return $states;
    }

    /**
     * @param  list<int>  $seatIds
     * @return Collection<int, Seat>
     */
    private function seatsInCoach(TrainRun $run, string $coachNo, array $seatIds): Collection
    {
        $coach = $run->train->rakeCoaches()->where('coach_no', $coachNo)->first()
            ?? throw new InvalidArgumentException("Coach {$coachNo} is not part of train {$run->train->number}.");

        $seats = Seat::query()
            ->where('coach_type_id', $coach->coach_type_id)
            ->whereIn('id', $seatIds)
            ->get();

        if ($seats->isEmpty() || $seats->count() !== count(array_unique($seatIds))) {
            throw new InvalidArgumentException("Some seats do not belong to coach {$coachNo}.");
        }

        return $seats;
    }

    /**
     * Whether another booking or a block now holds any of this booking's seat segments.
     */
    private function takenByOthers(Booking $booking): bool
    {
        foreach ($booking->seatBookings()->get() as $seat) {
            $clash = SeatBooking::query()
                ->where('train_run_id', $booking->train_run_id)
                ->where('coach_no', $seat->coach_no)
                ->where('seat_id', $seat->seat_id)
                ->where(fn ($q) => $q->whereNull('booking_id')->orWhere('booking_id', '!=', $booking->id))
                ->overlapping($booking->from_seq, $booking->to_seq)
                ->taken()
                ->exists();

            if ($clash) {
                return true;
            }
        }

        return false;
    }

    private function markReleased(Booking $booking, BookingStatus $as): void
    {
        $booking->seatBookings()
            ->where('status', SeatBookingStatus::Held->value)
            ->update(['status' => SeatBookingStatus::Cancelled->value]);
        $booking->update(['status' => $as]);
    }

    private function assertBookable(TrainRun $run, int $fromSeq, int $toSeq): void
    {
        if ($run->isCancelled()) {
            throw new BookingRefusedException('This train run has been cancelled.');
        }

        $calls = $run->train->scheduleStops()->with('routeStop')->get()
            ->map(fn (ScheduleStop $s) => $s->routeStop->sequence);

        if (! $calls->contains($fromSeq) || ! $calls->contains($toSeq)) {
            throw new BookingRefusedException('The train does not stop at both of those stations.');
        }

        $departure = $this->boardingDeparture($run, $fromSeq);

        if ($departure === null || $departure->isPast()) {
            throw new BookingRefusedException('This train has already left the boarding station.');
        }
    }

    /**
     * @param  list<array{coach_no: string, seat_id: int}>  $seats
     */
    private function assertSeatsSellable(TrainRun $run, array $seats, Channel $channel): void
    {
        $coaches = $run->train->rakeCoaches()->with('coachType.seats')->get()->keyBy('coach_no');

        foreach ($seats as $s) {
            /** @var RakeCoach|null $coach */
            $coach = $coaches->get($s['coach_no']);

            if ($coach === null) {
                throw new InvalidArgumentException("Coach {$s['coach_no']} is not part of train {$run->train->number}.");
            }

            if (! in_array($s['seat_id'], $coach->coachType->seats->modelKeys(), true)) {
                throw new InvalidArgumentException("Some seats do not belong to coach {$s['coach_no']}.");
            }

            if ($channel === Channel::Online && in_array($s['seat_id'], $this->counterQuotaSeatIds($coach), true)) {
                throw new BookingRefusedException('Some of those seats are sold only at the station counter.');
            }
        }
    }

    /**
     * @param  array<int, array{coach_no: string, seat_id: int|string}>  $seats
     * @return list<array{coach_no: string, seat_id: int}>
     */
    private function normaliseSeats(array $seats): array
    {
        $max = (int) config('railway.max_passengers');
        $unique = [];

        foreach ($seats as $s) {
            $unique["{$s['coach_no']}|{$s['seat_id']}"] = ['coach_no' => (string) $s['coach_no'], 'seat_id' => (int) $s['seat_id']];
        }

        if ($unique === []) {
            throw new BookingRefusedException('Choose at least one seat.');
        }

        if (count($unique) > $max) {
            throw new BookingRefusedException("A booking can have at most {$max} seats.");
        }

        return array_values($unique);
    }

    private function assertSegment(int $fromSeq, int $toSeq): void
    {
        if ($fromSeq >= $toSeq) {
            throw new InvalidArgumentException('The journey must end after it starts.');
        }
    }
}
