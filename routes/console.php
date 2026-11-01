<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep train runs generated for the next N days (config/railway.php: runs_ahead_days).
Schedule::command('runs:generate')->dailyAt('01:00');
