<?php

use App\Jobs\ProcessDuePawnInterestCompoundingJob;
use App\Jobs\ProcessDuePawnInterestAccrualsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Log::info('Scheduler working');
})->everyMinute();

Schedule::job(new ProcessDuePawnInterestCompoundingJob)->everyFifteenMinutes();

// Materialize pawn interest periods according to each tenant's local business date.
Schedule::job(new ProcessDuePawnInterestAccrualsJob)->everyFifteenMinutes();
