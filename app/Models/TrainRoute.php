<?php

namespace App\Models;

use Database\Factories\TrainRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A directional line: an ordered list of stations with distances.
 * Named TrainRoute to avoid clashing with Laravel's Route facade.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'is_active'])]
class TrainRoute extends Model
{
    /** @use HasFactory<TrainRouteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<RouteStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<Train, $this>
     */
    public function trains(): HasMany
    {
        return $this->hasMany(Train::class);
    }

    public function totalDistanceKm(): int
    {
        return (int) $this->stops()->max('distance_km');
    }
}
