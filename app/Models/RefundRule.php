<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cancelling at least hours_before departure refunds percent of the fare, less fee_poisha per booking.
 *
 * @property int $id
 * @property int $hours_before
 * @property int $percent
 * @property int $fee_poisha
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['hours_before', 'percent', 'fee_poisha'])]
class RefundRule extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'hours_before' => 'integer',
            'percent' => 'integer',
            'fee_poisha' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function label(): string
    {
        return "{$this->hours_before} h or more before departure";
    }
}
