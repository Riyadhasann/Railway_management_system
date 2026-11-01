<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of a train's timetable.
 *
 * @property int $id
 * @property int $train_id
 * @property int $route_stop_id
 * @property string|null $arrival_time HH:MM[:SS], null at the origin
 * @property string|null $departure_time HH:MM[:SS], null at the terminus
 * @property int $day_offset days after the journey date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Train $train
 * @property-read RouteStop $routeStop
 */
#[Fillable(['train_id', 'route_stop_id', 'arrival_time', 'departure_time', 'day_offset'])]
class ScheduleStop extends Model
{
    protected function casts(): array
    {
        return [
            'day_offset' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Train, $this>
     */
    public function train(): BelongsTo
    {
        return $this->belongsTo(Train::class);
    }

    /**
     * @return BelongsTo<RouteStop, $this>
     */
    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class);
    }

    public function arrivalOn(CarbonInterface $journeyDate): ?CarbonImmutable
    {
        return $this->at($journeyDate, $this->arrival_time);
    }

    public function departureOn(CarbonInterface $journeyDate): ?CarbonImmutable
    {
        return $this->at($journeyDate, $this->departure_time);
    }

    private function at(CarbonInterface $journeyDate, ?string $time): ?CarbonImmutable
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $time));

        return CarbonImmutable::parse($journeyDate->toDateString())
            ->addDays($this->day_offset)
            ->setTime($h, $m);
    }
}
