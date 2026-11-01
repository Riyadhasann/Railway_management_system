<?php

namespace App\Models;

use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $division
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'name_bn', 'division', 'is_active'])]
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
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
    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
