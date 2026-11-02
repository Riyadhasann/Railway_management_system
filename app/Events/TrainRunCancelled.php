<?php

namespace App\Events;

use App\Models\TrainRun;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A planner cancelled one train run. Listeners refund its passengers.
 */
class TrainRunCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TrainRun $run) {}
}
