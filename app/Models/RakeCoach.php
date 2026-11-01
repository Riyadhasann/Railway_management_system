<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One coach in a train's composition.
 *
 * @property int $id
 * @property int $train_id
 * @property string $coach_no
 * @property int $coach_type_id
 * @property int $position
 * @property int $counter_quota seats kept for counter sales
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Train $train
 * @property-read CoachType $coachType
 */
#[Fillable(['train_id', 'coach_no', 'coach_type_id', 'position', 'counter_quota'])]
class RakeCoach extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'counter_quota' => 'integer',
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
     * @return BelongsTo<CoachType, $this>
     */
    public function coachType(): BelongsTo
    {
        return $this->belongsTo(CoachType::class);
    }
}
