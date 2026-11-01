<?php

namespace App\Models;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A traveller on a booking, sitting in one seat.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $seat_booking_id
 * @property string $name
 * @property int $age
 * @property Gender $gender
 * @property string|null $nid
 * @property int|null $concession_id
 * @property int $fare_poisha after any concession
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Booking $booking
 * @property-read SeatBooking $seatBooking
 * @property-read Concession|null $concession
 */
#[Fillable(['booking_id', 'seat_booking_id', 'name', 'age', 'gender', 'nid', 'concession_id', 'fare_poisha'])]
class Passenger extends Model
{
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'gender' => Gender::class,
            'fare_poisha' => 'integer',
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
     * @return BelongsTo<SeatBooking, $this>
     */
    public function seatBooking(): BelongsTo
    {
        return $this->belongsTo(SeatBooking::class);
    }

    /**
     * @return BelongsTo<Concession, $this>
     */
    public function concession(): BelongsTo
    {
        return $this->belongsTo(Concession::class);
    }
}
