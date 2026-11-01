<?php

namespace App\Console\Commands;

use App\Enums\Channel;
use App\Exceptions\SeatUnavailableException;
use App\Models\TrainRun;
use App\Services\SeatInventoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One contender in booking:race. Waits until --start-at so all workers
 * fire together, then tries a single hold. Prints "OK <pnr>" or "TAKEN".
 */
#[Signature('booking:race-worker {run} {coach} {seat} {from} {to} {--start-at=}')]
#[Description('Internal: one parallel hold attempt for booking:race')]
#[Hidden]
class RaceHoldWorker extends Command
{
    public function handle(SeatInventoryService $inventory): int
    {
        $run = TrainRun::findOrFail((int) $this->argument('run'));

        $wait = (float) $this->option('start-at') - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        try {
            $booking = $inventory->hold(
                $run,
                [['coach_no' => (string) $this->argument('coach'), 'seat_id' => (int) $this->argument('seat')]],
                (int) $this->argument('from'),
                (int) $this->argument('to'),
                Channel::Counter,
            );
        } catch (SeatUnavailableException) {
            $this->line('TAKEN');

            return self::SUCCESS;
        }

        $this->line("OK {$booking->pnr}");

        return self::SUCCESS;
    }
}
