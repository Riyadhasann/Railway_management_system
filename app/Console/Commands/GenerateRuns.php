<?php

namespace App\Console\Commands;

use App\Services\TrainRunService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runs:generate {--days= : Days ahead to generate (default from config/railway.php)} {--from= : Start date, YYYY-MM-DD (default today)}')]
#[Description('Create daily train runs for active trains')]
class GenerateRuns extends Command
{
    public function handle(TrainRunService $runs): int
    {
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from')) : null;
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $created = $runs->generate($from, $days);

        $this->info("Created {$created} train run(s).");

        return self::SUCCESS;
    }
}
