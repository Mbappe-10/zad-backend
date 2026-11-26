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

Artisan::command('family-subscriptions:reconcile', function (): void {
    \App\Models\FamilyStorePurchase::query()
        ->whereIn('status', ['pending', 'expired', 'unknown'])
        ->whereNotNull('given_id')
        ->where('created_at', '>=', now()->subDays(2))
        ->where(function ($query): void {
            $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(1));
        })
        ->orderBy('id')->limit(50)->get()->each(function ($purchase): void {
            try {
                app(\App\Services\App\FamilyStorePurchaseService::class)->reconcile($purchase);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Family subscription payment reconciliation failed', [
                    'purchase_id' => $purchase->id, 'error' => $e->getMessage(),
                ]);
            }
        });
})->purpose('Verify pending family subscriptions directly with Moyasar');

Schedule::command('family-subscriptions:reconcile')->everyMinute()->withoutOverlapping();
