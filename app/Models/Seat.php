<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A seat or berth in a coach type's layout (not a physical seat on a train:
 * that is coach_no + seat_id on a train run).
 *
 * @property int $id
 * @property int $coach_type_id
 * @property string $seat_no
 * @property int $row
 * @property int $col
 * @property string|null $berth lower / upper
 * @property list<string>|null $flags women / accessible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CoachType $coachType
 */
#[Fillable(['coach_type_id', 'seat_no', 'row', 'col', 'berth', 'flags'])]
class Seat extends Model
{
    public const string FLAG_WOMEN = 'women';

    public const string FLAG_ACCESSIBLE = 'accessible';

    public const array BERTHS = ['lower', 'upper'];

    protected function casts(): array
    {
        return [
            'row' => 'integer',
            'col' => 'integer',
            'flags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CoachType, $this>
     */
    public function coachType(): BelongsTo
    {
        return $this->belongsTo(CoachType::class);
    }

    /**
     * @return HasMany<SeatBooking, $this>
     */
    public function seatBookings(): HasMany
    {
        return $this->hasMany(SeatBooking::class);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }
}
