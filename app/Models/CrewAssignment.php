<?php

namespace App\Models;

use App\Enums\StaffDesignation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One crew member on one train run. Written only by CrewService.
 *
 * @property int $id
 * @property int $train_run_id
 * @property int $staff_id
 * @property StaffDesignation $duty
 * @property CarbonImmutable $journey_date
 * @property int|null $assigned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TrainRun $trainRun
 * @property-read Staff $staff
 * @property-read User|null $assigner
 */
#[Fillable(['train_run_id', 'staff_id', 'duty', 'journey_date', 'assigned_by'])]
class CrewAssignment extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'duty' => StaffDesignation::class,
            'journey_date' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }

    /**
     * @return BelongsTo<TrainRun, $this>
     */
    public function trainRun(): BelongsTo
    {
        return $this->belongsTo(TrainRun::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
