<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TrainRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One train on one journey date (the departure date from the origin).
 *
 * @property int $id
 * @property int $train_id
 * @property CarbonImmutable $journey_date
 * @property RunStatus $status
 * @property int $delay_minutes
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Train $train
 */
#[Fillable(['train_id', 'journey_date', 'status', 'delay_minutes', 'cancelled_at', 'cancel_reason'])]
class TrainRun extends Model
{
    /** @use HasFactory<TrainRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'journey_date' => 'immutable_date',
            'status' => RunStatus::class,
            'delay_minutes' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Train, $this>
     */
    public function train(): BelongsTo
    {
        return $this->belongsTo(Train::class);
    }

    /**
     * @return HasMany<SeatBooking, $this>
     */
    public function seatBookings(): HasMany
    {
        return $this->hasMany(SeatBooking::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<RunStopTime, $this>
     */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(RunStopTime::class);
    }

    /**
     * @return HasMany<CrewAssignment, $this>
     */
    public function crewAssignments(): HasMany
    {
        return $this->hasMany(CrewAssignment::class);
    }

    /**
     * @return HasOne<LocoAssignment, $this>
     */
    public function locoAssignment(): HasOne
    {
        return $this->hasOne(LocoAssignment::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === RunStatus::Cancelled;
    }

    /**
     * What passengers are told about the train's progress, or null before it has any news.
     */
    public function progressLabel(): ?string
    {
        return match (true) {
            $this->status === RunStatus::Arrived => 'Arrived',
            $this->status === RunStatus::Cancelled => null,
            $this->delay_minutes > 0 => "Running {$this->delay_minutes} min late",
            $this->status === RunStatus::Running => 'Running on time',
            default => null,
        };
    }

    /**
     * Scheduled departure from the origin station.
     */
    public function scheduledDeparture(): ?CarbonImmutable
    {
        return $this->train->scheduleStops->first()?->departureOn($this->journey_date);
    }
}
