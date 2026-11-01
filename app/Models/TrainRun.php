<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TrainRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function isCancelled(): bool
    {
        return $this->status === RunStatus::Cancelled;
    }

    /**
     * Scheduled departure from the origin station.
     */
    public function scheduledDeparture(): ?CarbonImmutable
    {
        return $this->train->scheduleStops->first()?->departureOn($this->journey_date);
    }
}
