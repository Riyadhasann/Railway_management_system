<?php

namespace App\Models;

use App\Enums\LocoStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LocomotiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $loco_no
 * @property string $model
 * @property string $traction
 * @property string|null $home_shed
 * @property LocoStatus $status
 * @property CarbonImmutable|null $last_maintenance_on
 * @property CarbonImmutable|null $next_maintenance_due
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['loco_no', 'model', 'traction', 'home_shed', 'status', 'last_maintenance_on', 'next_maintenance_due'])]
class Locomotive extends Model
{
    /** @use HasFactory<LocomotiveFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'status' => LocoStatus::class,
            'last_maintenance_on' => 'immutable_date',
            'next_maintenance_due' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * @return HasMany<Maintenance, $this>
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    public function label(): string
    {
        return "{$this->loco_no} · {$this->model}";
    }
}
