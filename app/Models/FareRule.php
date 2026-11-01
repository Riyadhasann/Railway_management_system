<?php

namespace App\Models;

use App\Enums\SeatClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Fare per seat for one class over a distance band (km_from..km_to, inclusive).
 *
 * @property int $id
 * @property SeatClass $seat_class
 * @property int $km_from
 * @property int $km_to
 * @property int $fare_poisha
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['seat_class', 'km_from', 'km_to', 'fare_poisha'])]
class FareRule extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'seat_class' => SeatClass::class,
            'km_from' => 'integer',
            'km_to' => 'integer',
            'fare_poisha' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * Rules for a class whose band overlaps km_from..km_to.
     *
     * @param  Builder<FareRule>  $query
     */
    public function scopeOverlappingBand(Builder $query, SeatClass $class, int $kmFrom, int $kmTo): void
    {
        $query->where('seat_class', $class->value)
            ->where('km_from', '<=', $kmTo)
            ->where('km_to', '>=', $kmFrom);
    }
}
