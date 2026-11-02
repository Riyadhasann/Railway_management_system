<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One attempt to pay for a booking. Status changes happen only in PaymentService / RefundService.
 *
 * @property int $id
 * @property int $booking_id
 * @property PaymentMethod $method
 * @property string $reference
 * @property string|null $txn_id
 * @property int $amount_poisha
 * @property int $refunded_poisha
 * @property PaymentStatus $status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $paid_at
 * @property int|null $received_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Booking $booking
 * @property-read User|null $receiver
 */
#[Fillable(['booking_id', 'method', 'reference', 'txn_id', 'amount_poisha', 'refunded_poisha', 'status', 'failure_reason', 'paid_at', 'received_by'])]
class Payment extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_poisha' => 'integer',
            'refunded_poisha' => 'integer',
            'status' => PaymentStatus::class,
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'txn_id', 'refunded_poisha'])->logOnlyDirty();
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
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Money still held for this payment (paid and not yet refunded).
     */
    public function refundableAmount(): int
    {
        return in_array($this->status->value, PaymentStatus::captured(), true)
            ? max(0, $this->amount_poisha - $this->refunded_poisha)
            : 0;
    }
}
