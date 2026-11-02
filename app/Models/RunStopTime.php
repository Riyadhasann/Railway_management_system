<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Actual times at one stop of one run. Written only by TrainRunService::recordActualTime().
 *
 * @property int $id
 * @property int $train_run_id
 * @property int $schedule_stop_id
 * @property CarbonImmutable|null $actual_arrival
 * @property CarbonImmutable|null $actual_departure
 * @property int $delay_minutes
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TrainRun $trainRun
 * @property-read ScheduleStop $scheduleStop
 * @property-read User|null $recorder
 */
#[Fillable(['train_run_id', 'schedule_stop_id', 'actual_arrival', 'actual_departure', 'delay_minutes', 'recorded_by'])]
class RunStopTime extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'actual_arrival' => 'immutable_datetime',
            'actual_departure' => 'immutable_datetime',
            'delay_minutes' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['actual_arrival', 'actual_departure', 'delay_minutes'])->logOnlyDirty();
    }

    /**
     * @return BelongsTo<TrainRun, $this>
     */
    public function trainRun(): BelongsTo
    {
        return $this->belongsTo(TrainRun::class);
    }

    /**
     * @return BelongsTo<ScheduleStop, $this>
     */
    public function scheduleStop(): BelongsTo
    {
        return $this->belongsTo(ScheduleStop::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
