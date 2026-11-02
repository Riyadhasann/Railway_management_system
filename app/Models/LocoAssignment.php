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
 * The locomotive hauling one train run. Written only by CrewService.
 *
 * @property int $id
 * @property int $train_run_id
 * @property int $locomotive_id
 * @property CarbonImmutable $journey_date
 * @property int|null $assigned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TrainRun $trainRun
 * @property-read Locomotive $locomotive
 * @property-read User|null $assigner
 */
#[Fillable(['train_run_id', 'locomotive_id', 'journey_date', 'assigned_by'])]
class LocoAssignment extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'journey_date' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * @return BelongsTo<TrainRun, $this>
     */
    public function trainRun(): BelongsTo
    {
        return $this->belongsTo(TrainRun::class);
    }

    /**
     * @return BelongsTo<Locomotive, $this>
     */
    public function locomotive(): BelongsTo
    {
        return $this->belongsTo(Locomotive::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
