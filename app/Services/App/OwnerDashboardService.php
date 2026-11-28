<?php

namespace App\Services\App;

use App\Models\CashierBatch;
use App\Models\CashierDevice;
use App\Models\CashierMaterial;
use App\Models\CashierRecipeItem;
use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad Pro owner dashboard: today against the same day last week, profit,
 * peak hours, best and slow sellers, returning customers, staff, the month's goal,
 * live devices, alerts and plain-language «قرارات اليوم».
 */
class OwnerDashboardService
{
    public const TZ = 'Asia/Riyadh';

    public function build(Store $store): array
    {
        $now = now(self::TZ);
        $start = $now->copy()->subDays(35)->startOfDay();
        $receipts = SaleReceipt::query()->where('store_id', $store->id)->where('status', 'paid')
            ->where('issued_at', '>=', $start->copy()->utc())
            ->get(['id', 'sale_type', 'channel', 'payment_method', 'customer_phone', 'total', 'discount', 'items', 'issued_at', 'staff_name'])
            ->each(fn ($r) => $r->local = $r->issued_at->copy()->setTimezone(self::TZ));

        $today = $receipts->filter(fn ($r) => $r->local->isSameDay($now));
        // Same weekday last week, up to the same time: a fair comparison at any hour.
        $lastWeekDay = $now->copy()->subWeek();
        $lastWeek = $receipts->filter(fn ($r) => $r->local->isSameDay($lastWeekDay) && $r->local->lte($lastWeekDay));
        $yesterday = $receipts->filter(fn ($r) => $r->local->isSameDay($now->copy()->subDay()));

        $costs = $this->unitCosts($store);
        $todayTotals = $this->totals($today, $costs);
        $lastWeekTotals = $this->totals($lastWeek, $costs);

        return [
            'generated_at' => $now->toISOString(),
            'today' => $todayTotals + [
                'vs_last_week_pct' => $this->pct($todayTotals['total'], $lastWeekTotals['total']),
                'last_week_total' => $lastWeekTotals['total'],
                'yesterday_total' => round((float) $yesterday->sum('total'), 2),
            ],
            'hours' => $this->hours($receipts, $today, $now),
            'channels' => $this->channels($today),
            'top_products' => $this->topProducts($receipts->filter(fn ($r) => $r->local->gte($now->copy()->subDays(7)->startOfDay()))),
            'slow_products' => $this->slowProducts($store, $receipts->filter(fn ($r) => $r->local->gte($now->copy()->subDays(7)->startOfDay()))),
            'returning' => $this->returning($store, $today),
            'staff' => $this->staff($today),
            'month' => $this->month($store, $now),
            'devices' => $this->devices($store),
            'alerts' => $alerts = $this->alerts($store),
            'insights' => $this->insights($todayTotals, $lastWeekTotals, $receipts, $now, $alerts),
        ];
    }

    /** @return array<int, float> product id => unit cost from its recipe */
    private function unitCosts(Store $store): array
    {
        if (! Schema::hasTable('cashier_recipe_items')) {
            return [];
        }
        $ids = Product::query()->where('store_id', $store->id)->pluck('id');

        return CashierRecipeItem::query()->whereIn('product_id', $ids)->with('material')->get()->groupBy('product_id')
            ->map(fn ($items) => round((float) $items->sum(fn ($i) => $i->per_unit * (float) ($i->material?->unit_cost ?? 0)), 3))
            ->all();
    }

    private function totals(Collection $rows, array $costs): array
    {
        $total = round((float) $rows->sum('total'), 2);
        $profit = 0.0;
        $known = 0.0;
        foreach ($rows as $r) {
            foreach ((array) $r->items as $item) {
                $line = (float) ($item['total'] ?? ((float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 0)));
                $pid = (int) ($item['product_id'] ?? 0);
                if ($pid && isset($costs[$pid])) {
                    $profit += $line - $costs[$pid] * (int) ($item['quantity'] ?? 0);
                    $known += $line;
                }
            }
        }

        return [
            'total' => $total,
            'count' => $rows->count(),
            'average' => $rows->count() ? round($total / $rows->count(), 2) : 0,
            'discount' => round((float) $rows->sum('discount'), 2),
            'profit' => round($profit, 2),
            // Share of sales whose cost is known from a recipe (profit is estimated on that part).
            'profit_coverage_pct' => $total > 0 ? (int) round($known / $total * 100) : 0,
        ];
    }

    private function hours(Collection $all, Collection $today, Carbon $now): array
    {
        $usual = $all->filter(fn ($r) => $r->local->dayOfWeek === $now->dayOfWeek && ! $r->local->isSameDay($now));
        $weeks = max(1, $usual->groupBy(fn ($r) => $r->local->toDateString())->count());
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'hour' => $h,
                'today' => round((float) $today->filter(fn ($r) => $r->local->hour === $h)->sum('total'), 2),
                'usual' => round((float) $usual->filter(fn ($r) => $r->local->hour === $h)->sum('total') / $weeks, 2),
            ];
        }
        $peak = collect($out)->sortByDesc('usual')->first();

        return ['by_hour' => $out, 'peak_hour' => ($peak['usual'] ?? 0) > 0 ? $peak['hour'] : null, 'now_hour' => $now->hour];
    }

    private function channels(Collection $today): array
    {
        $labels = ['shop' => 'المحل', 'door' => 'عند الباب', 'bazaar' => 'معرض', 'online' => 'أونلاين'];
        $total = (float) $today->sum('total');

        return $today->groupBy(fn ($r) => $r->sale_type === SaleReceipt::TYPE_PLATFORM ? 'online' : ($r->channel ?: 'shop'))
            ->map(fn ($g, $k) => ['key' => $k, 'label' => $labels[$k] ?? $k, 'total' => round((float) $g->sum('total'), 2),
                'count' => $g->count(), 'pct' => $total > 0 ? (int) round($g->sum('total') / $total * 100) : 0])
            ->sortByDesc('total')->values()->all();
    }

    private function topProducts(Collection $rows): array
    {
        $acc = [];
        foreach ($rows as $r) {
            foreach ((array) $r->items as $item) {
                $key = (string) ($item['product_id'] ?? $item['name'] ?? '');
                $acc[$key] ??= ['product_id' => $item['product_id'] ?? null, 'name' => (string) ($item['name'] ?? ''), 'quantity' => 0, 'total' => 0.0];
                $acc[$key]['quantity'] += (int) ($item['quantity'] ?? 0);
                $acc[$key]['total'] += (float) ($item['total'] ?? 0);
            }
        }

        return collect($acc)->sortByDesc('quantity')->take(5)->map(fn ($a) => $a + ['total' => round($a['total'], 2)])->values()->all();
    }

    private function slowProducts(Store $store, Collection $rows): array
    {
        $sold = $rows->flatMap(fn ($r) => collect((array) $r->items)->pluck('product_id'))->filter()->map(fn ($id) => (int) $id)->unique();

        return Product::query()->where('store_id', $store->id)->whereNull('deleted_at')->where('status', 'active')
            ->where('created_at', '<', now()->subDays(7))->whereNotIn('id', $sold->all() ?: [0])
            ->orderBy('name_ar')->limit(5)->get(['id', 'name_ar'])->map(fn ($p) => ['product_id' => $p->id, 'name' => $p->name_ar])->all();
    }

    private function returning(Store $store, Collection $today): array
    {
        $phones = $today->pluck('customer_phone')->filter()->unique();
        if ($phones->isEmpty()) {
            return ['known_customers' => 0, 'returning' => 0, 'pct' => null];
        }
        $before = SaleReceipt::query()->where('store_id', $store->id)->where('status', 'paid')
            ->where('issued_at', '<', now(self::TZ)->startOfDay()->utc())->where('issued_at', '>=', now()->subDays(90))
            ->whereIn('customer_phone', $phones->all())->distinct()->pluck('customer_phone');

        return ['known_customers' => $phones->count(), 'returning' => $before->count(),
            'pct' => (int) round($before->count() / $phones->count() * 100)];
    }

    private function staff(Collection $today): array
    {
        return $today->filter(fn ($r) => $r->sale_type !== SaleReceipt::TYPE_PLATFORM)
            ->groupBy(fn ($r) => $r->staff_name ?: 'المالك')
            ->map(fn ($g, $name) => ['name' => $name, 'total' => round((float) $g->sum('total'), 2), 'count' => $g->count()])
            ->sortByDesc('total')->values()->take(6)->all();
    }

    private function month(Store $store, Carbon $now): array
    {
        $from = $now->copy()->startOfMonth();
        $total = (float) SaleReceipt::query()->where('store_id', $store->id)->where('status', 'paid')
            ->where('issued_at', '>=', $from->copy()->utc())->sum('total');
        $prevFrom = $from->copy()->subMonthNoOverflow();
        $prevTo = $prevFrom->copy()->addDays($now->day - 1)->setTime($now->hour, $now->minute);
        $prev = (float) SaleReceipt::query()->where('store_id', $store->id)->where('status', 'paid')
            ->whereBetween('issued_at', [$prevFrom->copy()->utc(), $prevTo->copy()->utc()])->sum('total');
        $goal = (float) (app(CashierBatchService::class)->settings($store)['monthly_goal'] ?? 0);
        $projection = $now->day > 0 ? $total / $now->day * $now->daysInMonth : $total;

        return [
            'total' => round($total, 2),
            'vs_last_month_pct' => $this->pct($total, $prev),
            'goal' => $goal > 0 ? round($goal, 2) : null,
            'goal_pct' => $goal > 0 ? (int) min(999, round($total / $goal * 100)) : null,
            'projection' => round($projection, 2),
            'days_left' => $now->daysInMonth - $now->day,
        ];
    }

    private function devices(Store $store): array
    {
        if (! Schema::hasTable('cashier_devices')) {
            return [];
        }

        return CashierDevice::query()->where('store_id', $store->id)->whereNull('revoked_at')->orderBy('kind')->orderBy('id')->get()
            ->map(function (CashierDevice $d): array {
                $station = DeviceLinkService::stationOf($d);
                $online = $d->last_seen_at !== null && $d->last_seen_at->gt(now()->subMinutes(3));

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'station' => $station,
                    'station_label' => DeviceLinkService::STATIONS[$station] ?? ($d->kind === 'customer' ? 'شاشة العميل' : $station),
                    'online' => $online,
                    'staff' => $d->staff_id ? DB::table('cashier_staff')->where('id', $d->staff_id)->value('name') : null,
                    'last_seen_at' => $d->last_seen_at?->toISOString(),
                ];
            })->all();
    }

    private function alerts(Store $store): array
    {
        $alerts = [];
        if (Schema::hasTable('cashier_materials')) {
            $low = CashierMaterial::query()->where('store_id', $store->id)->whereNotNull('low_alert')
                ->whereColumn('quantity', '<=', 'low_alert')->orderBy('quantity')->limit(5)->pluck('name');
            if ($low->isNotEmpty()) {
                $alerts[] = ['kind' => 'materials', 'tone' => 'red', 'text' => 'مواد قربت تخلص: '.$low->implode('، ')];
            }
        }
        if (Product::hasCashierColumns()) {
            $out = Product::query()->where('store_id', $store->id)->whereNull('deleted_at')->where('status', 'active')
                ->where('track_stock', true)->where('stock_quantity', '<=', 0)->pluck('name_ar');
            if ($out->isNotEmpty()) {
                $alerts[] = ['kind' => 'sold_out', 'tone' => 'red', 'text' => 'نفذت: '.$out->take(4)->implode('، ')];
            }
        }
        if (Schema::hasTable('cashier_batches')) {
            $pending = CashierBatch::query()->where('store_id', $store->id)->where('status', 'pending')->count();
            if ($pending > 0) {
                $alerts[] = ['kind' => 'approvals', 'tone' => 'amber', 'text' => "{$pending} دفعة تغليف بانتظار الاعتماد"];
            }
        }
        if (Schema::hasTable('cashier_shifts')) {
            $shift = DB::table('cashier_shifts')->where('store_id', $store->id)->whereNotNull('closed_at')
                ->where('closed_at', '>=', now()->subDays(2))->orderByDesc('closed_at')->first();
            if ($shift && $shift->counted_cash !== null && $shift->expected_cash !== null) {
                $diff = round((float) $shift->counted_cash - (float) $shift->expected_cash, 2);
                if (abs($diff) >= 1) {
                    $alerts[] = ['kind' => 'cash', 'tone' => 'amber', 'text' => 'فرق كاش '.($diff > 0 ? '+' : '').$diff.' ر.س في آخر وردية ('.($shift->staff_name ?: '').')'];
                }
            }
        }

        return $alerts;
    }

    private function insights(array $today, array $lastWeek, Collection $all, Carbon $now, array $alerts): array
    {
        $out = [];
        if ($lastWeek['total'] > 0) {
            $pct = $this->pct($today['total'], $lastWeek['total']);
            $out[] = $pct >= 0
                ? ['tone' => 'green', 'text' => "مبيعاتك أعلى بـ {$pct}٪ من نفس الوقت الأسبوع الماضي — استمر."]
                : ['tone' => 'amber', 'text' => 'مبيعاتك أقل بـ '.abs($pct).'٪ من نفس الوقت الأسبوع الماضي — جرّب عرض اليوم على شاشة المنيو.'];
        }
        $usual = $all->filter(fn ($r) => $r->local->dayOfWeek === $now->dayOfWeek && ! $r->local->isSameDay($now));
        $peak = $usual->groupBy(fn ($r) => $r->local->hour)->map(fn ($g) => $g->sum('total'))->sortDesc()->keys()->first();
        if ($peak !== null && $peak > $now->hour) {
            $out[] = ['tone' => 'blue', 'text' => 'ذروة يوم مثل اليوم الساعة '.$this->hourLabel((int) $peak).' — جهّز الكميات قبلها.'];
        }
        $top = $this->topProducts($all->filter(fn ($r) => $r->local->gte($now->copy()->subDays(7)->startOfDay())))[0] ?? null;
        if ($top) {
            $out[] = ['tone' => 'orange', 'text' => "«{$top['name']}» الأكثر مبيعاً هالأسبوع ({$top['quantity']}) — خلّه أول منتج في العرض التفاعلي."];
        }
        if ($today['discount'] > 0 && $today['total'] > 0 && $today['discount'] / ($today['total'] + $today['discount']) > .12) {
            $out[] = ['tone' => 'amber', 'text' => 'الخصومات اليوم أكثر من ١٢٪ من المبيعات — راجع حدود الخصم للموظفين.'];
        }
        foreach ($alerts as $a) {
            if ($a['kind'] === 'materials') {
                $out[] = ['tone' => 'red', 'text' => $a['text'].' — اطلبها قبل الذروة.'];
            }
        }

        return array_slice($out, 0, 4);
    }

    private function hourLabel(int $h): string
    {
        $h12 = $h % 12 === 0 ? 12 : $h % 12;

        return $h12.($h < 12 ? ' ص' : ' م');
    }

    private function pct(float $now, float $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }
}
