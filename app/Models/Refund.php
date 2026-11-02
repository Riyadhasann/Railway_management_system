<?php

namespace App\Models;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money returned against one payment. Created and processed only by RefundService.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $payment_id
 * @property RefundReason $reason
 * @property int $amount_poisha
 * @property int $fee_poisha
 * @property int $percent
 * @property string|null $hours_before
 * @property RefundStatus $status
 * @property string|null $txn_id
 * @property string|null $failure_reason
 * @property int|null $requested_by
 * @property CarbonImmutable|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Booking $booking
 * @property-read Payment $payment
 * @property-read User|null $requester
 */
#[Fillable(['booking_id', 'payment_id', 'reason', 'amount_poisha', 'fee_poisha', 'percent', 'hours_before', 'status', 'txn_id', 'failure_reason', 'requested_by', 'processed_at'])]
class Refund extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'reason' => RefundReason::class,
            'amount_poisha' => 'integer',
            'fee_poisha' => 'integer',
            'percent' => 'integer',
            'status' => RefundStatus::class,
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'amount_poisha', 'txn_id'])->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
