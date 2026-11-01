<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TrainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property string $name
 * @property int $train_route_id
 * @property list<int|string> $running_days ISO weekdays, 1 = Monday ... 7 = Sunday
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TrainRoute $trainRoute
 */
#[Fillable(['number', 'name', 'train_route_id', 'running_days', 'is_active'])]
class Train extends Model
{
    /** @use HasFactory<TrainFactory> */
    use HasFactory;

    public const array WEEKDAYS = [
        1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
    ];

    protected function casts(): array
    {
        return [
            'running_days' => 'array',
            'is_active' => 'boolean',
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
     * Timetable rows, ordered along the route.
     *
     * @return HasMany<ScheduleStop, $this>
     */
    public function scheduleStops(): HasMany
    {
        return $this->hasMany(ScheduleStop::class)->orderBy(
            RouteStop::select('sequence')->whereColumn('route_stops.id', 'schedule_stops.route_stop_id')
        );
    }

    /**
     * @return HasMany<TrainRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(TrainRun::class);
    }

    /**
     * @return list<int>
     */
    public function runningDays(): array
    {
        $days = array_map('intval', $this->running_days);
        sort($days);

        return array_values(array_unique($days));
    }

    public function runsOn(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), $this->runningDays(), true);
    }

    public function runningDaysLabel(): string
    {
        $days = $this->runningDays();

        if (count($days) === 7) {
            return 'Daily';
        }

        return implode(', ', array_map(fn (int $d) => self::WEEKDAYS[$d] ?? '?', $days));
    }

    public function label(): string
    {
        return "{$this->number} {$this->name}";
    }
}
