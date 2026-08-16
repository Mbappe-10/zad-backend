<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('journey:purge-expired')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/journey-retention.log'));