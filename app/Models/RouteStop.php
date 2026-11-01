<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $train_route_id
 * @property int $station_id
 * @property int $sequence
 * @property int $distance_km
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Station $station
 * @property-read TrainRoute $trainRoute
 */
#[Fillable(['train_route_id', 'station_id', 'sequence', 'distance_km'])]
class RouteStop extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'distance_km' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TrainRoute, $this>
     */
    public function trainRoute(): BelongsTo
    {
        return $this->belongsTo(TrainRoute::class);
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
