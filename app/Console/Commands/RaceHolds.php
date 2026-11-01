<?php

namespace App\Console\Commands;

use App\Enums\Channel;
use App\Enums\RunStatus;
use App\Models\Booking;
use App\Models\TrainRun;
use App\Services\SeatInventoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Concurrency check: N separate PHP processes try to hold the same seat at the
 * same moment. Exactly one must succeed. Needs MySQL/PostgreSQL (row locks).
 */
#[Signature('booking:race {--run= : Train run id (default: next bookable run)} {--attempts=20 : Parallel requests} {--warmup=3 : Seconds for the workers to boot before they all fire} {--keep : Keep the winning booking instead of releasing it}')]
#[Description('Fire parallel holds at one seat and check that exactly one succeeds')]
class RaceHolds extends Command
{
    public function handle(SeatInventoryService $inventory): int
    {
        if (config('database.default') === 'sqlite') {
            $this->error('SQLite has no row locks; run this against MySQL.');

            return self::FAILURE;
        }

        $run = $this->option('run') ? TrainRun::find($this->option('run')) : $this->nextRun();

        if ($run === null) {
            $this->error('No bookable train run found.');

            return self::FAILURE;
        }

        [$fromSeq, $toSeq] = $inventory->fullJourney($run);
        $target = $this->freeSeat($inventory, $run, $fromSeq, $toSeq);

        if ($target === null) {
            $this->error("No free seat on run {$run->id}.");

            return self::FAILURE;
        }

        $attempts = max(2, (int) $this->option('attempts'));
        $startAt = microtime(true) + max(1, (int) $this->option('warmup'));
        $this->info("Run {$run->id} ({$run->train->label()}, {$run->journey_date->toDateString()}): {$attempts} parallel holds on seat {$target['coach_no']}/{$target['seat_id']}...");

        $processes = [];
        foreach (range(1, $attempts) as $i) {
            $process = new Process(
                [
                    PHP_BINARY, base_path('artisan'), 'booking:race-worker',
                    (string) $run->id, $target['coach_no'], (string) $target['seat_id'], (string) $fromSeq, (string) $toSeq,
                    '--start-at='.$startAt,
                ],
                base_path(),
                $this->childEnv(),
                null,
                120,
            );
            $process->start();
            $processes[] = $process;
        }

        $results = ['OK' => [], 'TAKEN' => [], 'ERROR' => []];
        foreach ($processes as $process) {
            $process->wait();
            $line = trim($process->getOutput());
            $kind = str_starts_with($line, 'OK ') ? 'OK' : ($line === 'TAKEN' ? 'TAKEN' : 'ERROR');
            $results[$kind][] = $kind === 'ERROR' ? trim($line.' '.$process->getErrorOutput()) : $line;
        }

        $this->line(sprintf('Succeeded: %d, seat taken: %d, errors: %d', count($results['OK']), count($results['TAKEN']), count($results['ERROR'])));
        foreach (array_slice($results['ERROR'], 0, 3) as $error) {
            $this->warn('  '.mb_strimwidth($error, 0, 300, '...'));
        }

        $pnrs = array_map(fn (string $l) => substr($l, 3), $results['OK']);
        if (! $this->option('keep')) {
            Booking::whereIn('pnr', $pnrs)->get()->each(fn (Booking $b) => $inventory->release($b));
        }

        if (count($results['OK']) === 1 && $results['ERROR'] === []) {
            $this->info('PASS: exactly one hold succeeded ('.$pnrs[0].').');

            return self::SUCCESS;
        }

        $this->error('FAIL: expected exactly one successful hold.');

        return self::FAILURE;
    }

    private function nextRun(): ?TrainRun
    {
        return TrainRun::query()
            ->where('status', RunStatus::Scheduled->value)
            ->where('journey_date', '>', now()->toDateString())
            ->whereHas('train.rakeCoaches')
            ->orderBy('journey_date')
            ->first();
    }

    /**
     * @return array{coach_no: string, seat_id: int}|null
     */
    private function freeSeat(SeatInventoryService $inventory, TrainRun $run, int $fromSeq, int $toSeq): ?array
    {
        foreach ($inventory->availability($run, $fromSeq, $toSeq, Channel::Counter) as $coach) {
            foreach ($coach->seats as $seat) {
                if ($seat->state->isAvailable()) {
                    return ['coach_no' => $coach->coachNo, 'seat_id' => $seat->seatId];
                }
            }
        }

        return null;
    }

    /**
     * Workers must use exactly the same database as this process.
     *
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $name = (string) config('database.default');
        $db = (array) config("database.connections.{$name}");

        return [
            'APP_ENV' => (string) app()->environment(),
            'DB_CONNECTION' => $name,
            'DB_HOST' => (string) ($db['host'] ?? ''),
            'DB_PORT' => (string) ($db['port'] ?? ''),
            'DB_DATABASE' => (string) ($db['database'] ?? ''),
            'DB_USERNAME' => (string) ($db['username'] ?? ''),
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
            'DB_URL' => '',
        ];
    }
}
