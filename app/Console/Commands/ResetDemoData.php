<?php

namespace App\Console\Commands;

use App\Services\DemoDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:reset {--force : Don\'t ask for confirmation}')]
#[Description('Wipe the database and load the demo data with a month of booking history')]
class ResetDemoData extends Command
{
    public function handle(DemoDataService $demo): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Demo data can not be reset in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This deletes ALL data, including bookings made by testers. Continue?')) {
            return self::FAILURE;
        }

        $this->info('Resetting demo data (a minute or two)...');
        $demo->reset();
        $this->info('Done. Demo account passwords are unchanged.');

        return self::SUCCESS;
    }
}
