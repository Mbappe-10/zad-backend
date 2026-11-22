<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\PlatformControl;
use Illuminate\Support\Facades\DB;

Artisan::command('orders:remind-scheduled', function (): void {
    $settings = PlatformControl::cachedValues()['orders_sales'] ?? [];
    $offsets = $settings['scheduleReminderHours'] ?? [48, 24, 5];
    sort($offsets, SORT_NUMERIC);
    $now = now('UTC');
    $previous = 0;
    foreach ($offsets as $hours) {
        // Only send the current interval after downtime or a short-notice booking.
        $due = DB::table('order_schedules as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->join('stores', 'stores.id', '=', 'o.store_id')
            ->join('app_profiles as p', 'p.productive_family_id', '=', 'stores.productive_family_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', ['pending', 'accepted', 'preparing'])
            ->where('s.family_status', 'confirmed')
            ->where('s.delivery_at', '>', $now->copy()->addHours($previous))
            ->where('s.delivery_at', '<=', $now->copy()->addHours((int) $hours))
            ->select('o.number', 's.order_id', 's.delivery_at', 'p.user_id')
            ->orderBy('s.order_id')->orderBy('p.user_id')->cursor();
        foreach ($due as $row) {
            DB::table('in_app_notifications')->insertOrIgnore([
                'user_id' => $row->user_id,
                'title' => 'تذكير بموعد طلب مجدول',
                'body' => "اقترب موعد تسليم الطلب {$row->number}. راجع تفاصيل الموعد.",
                'action_url' => '/family/orders',
                'data' => json_encode(['order_id' => $row->order_id, 'hours' => $hours], JSON_THROW_ON_ERROR),
                'idempotency_key' => hash('sha256', "schedule|{$row->order_id}|{$row->delivery_at}|{$hours}|{$row->user_id}"),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $previous = (int) $hours;
    }
});

Schedule::command('orders:remind-scheduled')->everyMinute()->withoutOverlapping();

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
