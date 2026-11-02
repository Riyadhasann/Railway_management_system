<?php

namespace App\Models;

use App\Enums\ScanResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One QR scan by a TTE. Written only by TicketService::validate().
 *
 * @property int $id
 * @property int|null $booking_id
 * @property int $train_run_id
 * @property int $tte_id
 * @property ScanResult $result
 * @property string|null $pnr
 * @property CarbonImmutable $scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Booking|null $booking
 * @property-read TrainRun $trainRun
 * @property-read User $tte
 */
#[Fillable(['booking_id', 'train_run_id', 'tte_id', 'result', 'pnr', 'scanned_at'])]
class TicketScan extends Model
{
    protected function casts(): array
    {
        return [
            'result' => ScanResult::class,
            'scanned_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function tte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tte_id');
    }
}
