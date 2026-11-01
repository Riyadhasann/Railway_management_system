<?php

namespace App\Console\Commands;

use App\Services\SeatInventoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('holds:release')]
#[Description('Expire unpaid seat holds whose time has run out')]
class ReleaseHolds extends Command
{
    public function handle(SeatInventoryService $inventory): int
    {
        $count = $inventory->releaseExpired();

        $this->info("Released {$count} expired hold(s).");

        return self::SUCCESS;
    }
}
