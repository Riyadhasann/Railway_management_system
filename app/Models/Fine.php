<?php

namespace App\Models;

use App\Enums\FineReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cash penalty collected on board. Written only by CheckingService.
 *
 * @property int $id
 * @property string $receipt_no
 * @property int $train_run_id
 * @property int|null $booking_id
 * @property int $tte_id
 * @property string $passenger_name
 * @property FineReason $reason
 * @property int $amount_poisha
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TrainRun $trainRun
 * @property-read Booking|null $booking
 * @property-read User $tte
 */
#[Fillable(['receipt_no', 'train_run_id', 'booking_id', 'tte_id', 'passenger_name', 'reason', 'amount_poisha', 'note'])]
class Fine extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'reason' => FineReason::class,
            'amount_poisha' => 'integer',
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
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tte_id');
    }
}
