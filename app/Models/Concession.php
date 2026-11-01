<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A per-passenger discount, e.g. child or senior citizen.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $percent 0..100
 * @property int|null $min_age
 * @property int|null $max_age
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'percent', 'min_age', 'max_age', 'is_active'])]
class Concession extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * @return HasMany<Passenger, $this>
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    public function allowsAge(int $age): bool
    {
        return ($this->min_age === null || $age >= $this->min_age)
            && ($this->max_age === null || $age <= $this->max_age);
    }

    public function label(): string
    {
        $ages = match (true) {
            $this->min_age !== null && $this->max_age !== null => " ({$this->min_age}-{$this->max_age} yrs)",
            $this->min_age !== null => " ({$this->min_age}+ yrs)",
            $this->max_age !== null => " (up to {$this->max_age} yrs)",
            default => '',
        };

        return "{$this->name} -{$this->percent}%{$ages}";
    }
}
