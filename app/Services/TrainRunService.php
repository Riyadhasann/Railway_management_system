<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Events\TrainRunCancelled;
use App\Models\RunStopTime;
use App\Models\ScheduleStop;
use App\Models\Train;
use App\Models\TrainRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TrainRunService
{
    /** An actual time this far before the timetable is taken as a typing mistake. */
    public const int MAX_EARLY_HOURS = 2;

    /**
     * Create train runs for every active train on each date it runs, from $from
     * for $days days. Existing runs (including cancelled ones) are left untouched,
     * so it is safe to run repeatedly.
     *
     * @return int number of runs created
     */
    public function generate(?CarbonInterface $from = null, ?int $days = null): int
    {
        $from = CarbonImmutable::parse(($from ?? now())->toDateString());
        $days ??= (int) config('railway.runs_ahead_days');
        $created = 0;

        $until = $from->addDays($days - 1);

        Train::query()->where('is_active', true)->each(function (Train $train) use ($from, $until, $days, &$created) {
            // Compare by calendar date: drivers store DATE values differently (SQLite keeps a time part).
            $existing = $train->runs()
                ->whereDate('journey_date', '>=', $from->toDateString())
                ->whereDate('journey_date', '<=', $until->toDateString())
                ->pluck('journey_date')
                ->map(fn (CarbonInterface $d) => $d->toDateString())
                ->flip();

            for ($i = 0; $i < $days; $i++) {
                $date = $from->addDays($i);

                if (! $train->runsOn($date) || $existing->has($date->toDateString())) {
                    continue;
                }

                TrainRun::create([
                    'train_id' => $train->id,
                    'journey_date' => $date->toDateString(),
                    'status' => RunStatus::Scheduled,
                ]);
                $created++;
            }
        });

        return $created;
    }

    /**
     * Cancel one train run. TrainRunCancelled (after commit) releases its holds
     * and queues a full refund for every confirmed booking.
     */
    public function cancel(TrainRun $run, string $reason): TrainRun
    {
        return DB::transaction(function () use ($run, $reason) {
            /** @var TrainRun $locked */
            $locked = TrainRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RunStatus::Scheduled) {
                throw new DomainException("Only scheduled runs can be cancelled (this run is {$locked->status->value}).");
            }

            $locked->update([
                'status' => RunStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            TrainRunCancelled::dispatch($locked);

            return $locked;
        });
    }

    /**
     * Station master records the actual arrival and/or departure ("HH:MM") at one stop.
     * The run's delay becomes the delay at the furthest stop recorded so far; the run is
     * running once it has any actual time, and arrived once the terminus arrival is in.
     *
     * @throws DomainException if the times don't make sense
     */
    public function recordActualTime(TrainRun $run, ScheduleStop $stop, ?string $arrival, ?string $departure, User $by): RunStopTime
    {
        if ($stop->train_id !== $run->train_id) {
            throw new InvalidArgumentException('That stop is not on this train\'s timetable.');
        }

        $scheduledArrival = $stop->arrivalOn($run->journey_date);
        $scheduledDeparture = $stop->departureOn($run->journey_date);
        $actualArrival = $this->actualAt($arrival, $scheduledArrival, 'arrival');
        $actualDeparture = $this->actualAt($departure, $scheduledDeparture, 'departure');

        if ($actualArrival === null && $actualDeparture === null) {
            throw new DomainException('Enter an arrival or a departure time.');
        }

        if ($actualArrival !== null && $actualDeparture !== null && $actualDeparture->lt($actualArrival)) {
            throw new DomainException('The departure can\'t be before the arrival.');
        }

        foreach ([$actualArrival, $actualDeparture] as $time) {
            if ($time !== null && $time->gt(now()->addMinutes(5))) {
                throw new DomainException('Actual times can\'t be in the future.');
            }
        }

        // The latest event at the stop says how late the train is now.
        $delay = $actualDeparture !== null && $scheduledDeparture !== null
            ? $scheduledDeparture->diffInMinutes($actualDeparture, false)
            : ($scheduledArrival === null || $actualArrival === null ? 0 : $scheduledArrival->diffInMinutes($actualArrival, false));

        return DB::transaction(function () use ($run, $stop, $actualArrival, $actualDeparture, $delay, $by) {
            /** @var TrainRun $locked */
            $locked = TrainRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw new DomainException('This run has been cancelled.');
            }

            $time = RunStopTime::updateOrCreate(
                ['train_run_id' => $locked->id, 'schedule_stop_id' => $stop->id],
                [
                    'actual_arrival' => $actualArrival,
                    'actual_departure' => $actualDeparture,
                    'delay_minutes' => (int) max(0, round($delay)),
                    'recorded_by' => $by->id,
                ],
            );

            $this->updateProgress($locked);
            $run->refresh();

            return $time;
        });
    }

    private function updateProgress(TrainRun $run): void
    {
        $stops = $run->train->scheduleStops()->with('routeStop')->get()->keyBy('id');
        $times = $run->stopTimes()->get()
            ->sortBy(fn (RunStopTime $t) => $stops->get($t->schedule_stop_id)?->routeStop->sequence ?? 0);

        /** @var RunStopTime|null $furthest */
        $furthest = $times->last();
        $terminus = $stops->sortBy(fn (ScheduleStop $s) => $s->routeStop->sequence)->last();
        $arrived = $terminus !== null && $times->contains(fn (RunStopTime $t) => $t->schedule_stop_id === $terminus->id && $t->actual_arrival !== null);

        $run->update([
            'delay_minutes' => $furthest->delay_minutes ?? 0,
            'status' => match (true) {
                $arrived => RunStatus::Arrived,
                $furthest !== null => RunStatus::Running,
                default => $run->status,
            },
        ]);
    }

    /**
     * "HH:MM" as a moment near the scheduled time: a train due at 23:50 that arrives at 00:20
     * arrived the next day. Null if no time was given.
     */
    private function actualAt(?string $hhmm, ?CarbonImmutable $scheduled, string $what): ?CarbonImmutable
    {
        $hhmm = trim((string) $hhmm);

        if ($hhmm === '') {
            return null;
        }

        if ($scheduled === null) {
            throw new DomainException("The train has no scheduled {$what} at this stop.");
        }

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            throw new DomainException("Enter the {$what} time as HH:MM.");
        }

        $actual = $scheduled->setTime((int) $m[1], (int) $m[2]);
        $actual = match (true) {
            $actual->lt($scheduled->subHours(12)) => $actual->addDay(),
            $actual->gt($scheduled->addHours(12)) => $actual->subDay(),
            default => $actual,
        };

        if ($actual->lt($scheduled->subHours(self::MAX_EARLY_HOURS))) {
            throw new DomainException("That {$what} is more than ".self::MAX_EARLY_HOURS." hours before the scheduled {$scheduled->format('H:i')}. Check the time.");
        }

        return $actual;
    }
}
