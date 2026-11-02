<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One workshop visit of a locomotive. Open (completed_on null) while the work is going on.
 *
 * @property int $id
 * @property int $locomotive_id
 * @property MaintenanceType $type
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable|null $completed_on
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Locomotive $locomotive
 */
#[Fillable(['locomotive_id', 'type', 'started_on', 'completed_on', 'description'])]
class Maintenance extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'started_on' => 'immutable_date',
            'completed_on' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Locomotive, $this>
     */
    public function locomotive(): BelongsTo
    {
        return $this->belongsTo(Locomotive::class);
    }

    public function isOpen(): bool
    {
        return $this->completed_on === null;
    }
}
