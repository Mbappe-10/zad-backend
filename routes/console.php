<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('journey:purge-expired')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(
        storage_path('logs/journey-retention.log'),
    );

Schedule::command('order-chat:purge-expired')
    ->dailyAt('03:15')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30)
    ->appendOutputTo(
        storage_path('logs/order-chat-retention.log'),
    );

Schedule::command('order-settlement:release-due')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(
        storage_path('logs/order-settlements.log'),
    );


/* ZAD_AUTOMATIC_SETTLEMENT_RELEASE_V1 */
Schedule::command('order-settlement:release-due')
    ->everyMinute()
    ->withoutOverlapping();