<?php

namespace App\Models;

use App\Enums\SeatBookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One seat on one train run for the segment from_seq -> to_seq.
 * Written only through SeatInventoryService.
 *
 * @property int $id
 * @property int|null $booking_id
 * @property int $train_run_id
 * @property string $coach_no
 * @property int $seat_id
 * @property int $from_seq
 * @property int $to_seq
 * @property SeatBookingStatus $status
 * @property CarbonImmutable|null $hold_until
 * @property int|null $blocked_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Booking|null $booking
 * @property-read TrainRun $trainRun
 * @property-read Seat $seat
 * @property-read User|null $blocker
 */
#[Fillable(['booking_id', 'train_run_id', 'coach_no', 'seat_id', 'from_seq', 'to_seq', 'status', 'hold_until', 'blocked_by', 'reason'])]
class SeatBooking extends Model
{
    protected function casts(): array
    {
        return [
            'from_seq' => 'integer',
            'to_seq' => 'integer',
            'status' => SeatBookingStatus::class,
            'hold_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<TrainRun, $this>
     */
    public function trainRun(): BelongsTo
    {
        return $this->belongsTo(TrainRun::class);
    }

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * Rows that make a seat unavailable right now: booked, blocked,
     * or held with an unexpired hold.
     *
     * @param  Builder<SeatBooking>  $query
     */
    public function scopeTaken(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereIn('status', SeatBookingStatus::alwaysTaken())
            ->orWhere(fn (Builder $q) => $q
                ->where('status', SeatBookingStatus::Held->value)
                ->where('hold_until', '>', now())));
    }

    /**
     * Rows whose segment overlaps from -> to.
     *
     * @param  Builder<SeatBooking>  $query
     */
    public function scopeOverlapping(Builder $query, int $fromSeq, int $toSeq): void
    {
        $query->where('from_seq', '<', $toSeq)->where('to_seq', '>', $fromSeq);
    }
}
