<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Events\TrainRunCancelled;
use App\Models\Train;
use App\Models\TrainRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class TrainRunService
{
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
}
