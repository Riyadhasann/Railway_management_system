<?php

namespace App\Models;

use App\Enums\SeatClass;
use Database\Factories\CoachTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A coach design: class plus a seat layout on a rows x cols grid.
 * One layout per coach type (prototype simplification).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property SeatClass $seat_class
 * @property int $layout_rows
 * @property int $layout_cols
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'seat_class', 'layout_rows', 'layout_cols', 'is_active'])]
class CoachType extends Model
{
    /** @use HasFactory<CoachTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'seat_class' => SeatClass::class,
            'layout_rows' => 'integer',
            'layout_cols' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class)->orderBy('row')->orderBy('col');
    }

    /**
     * @return HasMany<RakeCoach, $this>
     */
    public function rakeCoaches(): HasMany
    {
        return $this->hasMany(RakeCoach::class);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->seat_class->getLabel()})";
    }
}
