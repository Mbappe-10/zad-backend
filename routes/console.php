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
Artisan::command('orders:expire-unaccepted', function (): void {
    $gate = app(\App\Services\App\PlatformOperationGate::class);
    if (! $gate->enabled('operations', 'schedulerEnabled', true)) {
        return;
    }
    $minutes = (int) $gate->number('orders_sales', 'automaticOrderExpiryMinutes', 0);
    if ($minutes < 1) {
        return;
    }
    DB::table('orders')
        ->whereNull('deleted_at')
        ->where('status', 'pending')
        ->where('payment_status', 'unpaid')
        ->where('created_at', '<=', now('UTC')->subMinutes($minutes))
        ->update(['status' => 'cancelled', 'cancelled_at' => now('UTC'), 'updated_at' => now('UTC')]);
});

Schedule::command('orders:expire-unaccepted')->everyMinute()->withoutOverlapping();

// Startup diagnostic: shows which commit runs, which database it talks to,
// and where the family-store tables really live. Never fails the boot.
Artisan::command('zad:db-diagnose', function (): void {
    try {
        $c = DB::connection();
        $this->line('ZAD-DIAG commit='.(getenv('RENDER_GIT_COMMIT') ?: 'unknown')
            .' host='.$c->getConfig('host').' db='.$c->getDatabaseName());
        $row = DB::selectOne('select current_database() as db, current_schema() as schema, current_setting(\'search_path\') as path, current_user as usr');
        $this->line("ZAD-DIAG server db={$row->db} schema={$row->schema} search_path={$row->path} user={$row->usr}");
        foreach (DB::select("select table_schema, table_name from information_schema.tables where table_name in ('migrations','family_store_plans','family_store_grants','family_store_purchases') order by table_name, table_schema") as $t) {
            $this->line("ZAD-DIAG table {$t->table_schema}.{$t->table_name}");
        }
        foreach (DB::table('migrations')->where('migration', 'like', '2026_11_2%')->orderBy('id')->get(['migration', 'batch']) as $m) {
            $this->line("ZAD-DIAG ran {$m->migration} batch={$m->batch}");
        }
    } catch (\Throwable $e) {
        $this->line('ZAD-DIAG failed: '.$e->getMessage());
    }
})->purpose('Print database diagnostics at startup');

Schedule::command('sale-receipts:sync --limit=300')->everyTenMinutes()->withoutOverlapping();

Artisan::command('zad:cashier-purge-media', function (): void {
    $days = app(\App\Services\App\CashierZadService::class)->settings()['media_retention_days'];
    $count = app(\App\Services\App\CashierBatchService::class)->purgePhotos($days);
    $this->info("Deleted {$count} packaging photos older than {$days} days.");
})->purpose('Delete Cashier Zad packaging photos after the retention period');

Schedule::command('zad:cashier-purge-media')->dailyAt('03:30')->withoutOverlapping();

// ZATCA phase 2: report simplified invoices / credit notes and retry anything still pending (24-hour window).
Artisan::command('zad:zatca-submit', function (): void {
    $sent = app(\App\Services\App\ZatcaService::class)->submitPending(200);
    if ($sent > 0) {
        $this->info("ZATCA documents processed: {$sent}");
    }
})->purpose('Send pending ZATCA e-invoices');
Schedule::command('zad:zatca-submit')->everyMinute()->withoutOverlapping(10);

// Cashier Zad Pro supply: every morning, draft purchase orders for materials at their
// alert level, refill transfers for branches under their par level, and remind owners
// about supplier invoices that are due.
Artisan::command('zad:cashier-supply-daily', function (): void {
    $supply = app(\App\Services\App\CashierSupplyService::class);
    if (! $supply->ready()) {
        return;
    }
    $inventory = app(\App\Services\App\CashierInventoryService::class);
    $storeIds = DB::table('cashier_materials')->whereNotNull('low_alert')->distinct()->pluck('store_id')
        ->merge(DB::table('cashier_branches')->where('is_main', false)->distinct()->pluck('store_id'))
        ->merge(DB::table('cashier_purchases')->where('status', 'received')->whereColumn('paid', '<', 'total')->distinct()->pluck('store_id'))
        ->unique();
    foreach ($storeIds as $id) {
        $store = \App\Models\Store::query()->find($id);
        if (! $store || ! $inventory->active($store)) {
            continue;
        }
        try {
            $supply->runDaily($store);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('cashier supply daily failed', ['store' => $id, 'error' => $e->getMessage()]);
        }
    }
})->purpose('Draft reorders and branch refills, remind about due supplier invoices');

Schedule::command('zad:cashier-supply-daily')->dailyAt('06:10')->timezone('Asia/Riyadh')->withoutOverlapping(30);
