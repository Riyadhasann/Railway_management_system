<?php

namespace App\Services;

use App\Data\CoachMap;
use App\Data\SeatCell;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatState;
use App\Exceptions\SeatUnavailableException;
use App\Models\RakeCoach;
use App\Models\Seat;
use App\Models\SeatBooking;
use App\Models\TrainRun;
use App\Models\User;
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
 *  - every write locks the train_run row first, so writes to one run are serialised.
 */
class SeatInventoryService
{
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
    public function availability(TrainRun $run, int $fromSeq, int $toSeq): array
    {
        $this->assertSegment($fromSeq, $toSeq);

        $states = $this->takenStates($run, $fromSeq, $toSeq);

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

    private function assertSegment(int $fromSeq, int $toSeq): void
    {
        if ($fromSeq >= $toSeq) {
            throw new InvalidArgumentException('The journey must end after it starts.');
        }
    }
}
