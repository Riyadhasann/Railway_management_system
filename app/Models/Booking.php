<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One reservation (PNR) on a train run for the segment from_seq -> to_seq.
 * Status changes happen only in SeatInventoryService / BookingService.
 *
 * @property int $id
 * @property string $pnr
 * @property int|null $user_id
 * @property int|null $sold_by
 * @property int $train_run_id
 * @property Channel $channel
 * @property int $from_seq
 * @property int $to_seq
 * @property int $total_poisha
 * @property BookingStatus $status
 * @property CarbonImmutable|null $hold_until
 * @property string|null $contact_phone
 * @property string|null $contact_email
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $seller
 * @property-read TrainRun $trainRun
 */
#[Fillable(['pnr', 'user_id', 'sold_by', 'train_run_id', 'channel', 'from_seq', 'to_seq', 'total_poisha', 'status', 'hold_until', 'contact_phone', 'contact_email', 'confirmed_at', 'cancelled_at', 'cancelled_by'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'from_seq' => 'integer',
            'to_seq' => 'integer',
            'total_poisha' => 'integer',
            'status' => BookingStatus::class,
            'hold_until' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'total_poisha'])->logOnlyDirty();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    /**
     * @return BelongsTo<TrainRun, $this>
     */
    public function trainRun(): BelongsTo
    {
        return $this->belongsTo(TrainRun::class);
    }

    /**
     * @return HasMany<SeatBooking, $this>
     */
    public function seatBookings(): HasMany
    {
        return $this->hasMany(SeatBooking::class);
    }

    /**
     * @return HasMany<Passenger, $this>
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The payment that bought this booking (the first successful one).
     */
    public function paidPayment(): ?Payment
    {
        return $this->payments()
            ->whereIn('status', PaymentStatus::captured())
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Confirmed: a valid ticket exists for it.
     */
    public function hasTicket(): bool
    {
        return $this->status === BookingStatus::Confirmed || $this->status === BookingStatus::Completed;
    }

    /**
     * Unpaid and the hold has not run out yet.
     */
    public function isHeld(): bool
    {
        return $this->status === BookingStatus::PendingPayment
            && $this->hold_until !== null
            && $this->hold_until->isFuture();
    }

    public function holdSecondsLeft(): int
    {
        return $this->isHeld() ? (int) max(0, now()->diffInSeconds($this->hold_until, false)) : 0;
    }

    public function getRouteKeyName(): string
    {
        return 'pnr';
    }
}
