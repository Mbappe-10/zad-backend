<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\Store;
use App\Services\App\FamilyStorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales for the family's own store, tiered by plan:
 * every plan gets totals and per-order net; sales_insights adds the chart, top
 * dishes and peak times; sales_pro adds period comparison, customers and export.
 */
class FamilySalesController extends Controller
{
    private const TZ = 'Asia/Riyadh';
    private const WEEKDAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    public function __construct(private readonly FamilyStorefrontService $storefront) {}

    public function show(Request $request): JsonResponse
    {
        $store = $this->familyStore($request);
        $entitlement = $this->storefront->catalogEntitlement($store->productiveFamily);
        $capabilities = $entitlement['capabilities'] ?? [];
        $insights = ! empty($capabilities['sales_insights']);
        $pro = ! empty($capabilities['sales_pro']);

        $now = now(self::TZ);
        $from = $now->copy()->subMonthNoOverflow()->startOfMonth()->min($now->copy()->subDays(29)->startOfDay());
        $orders = $this->completedOrders($store, $from, $now->copy()->endOfDay());
        $settlements = $this->settlements($orders);

        $window = fn (Carbon $start, ?Carbon $end = null): Collection => $orders->filter(
            fn (Order $o): bool => $this->at($o)->gte($start) && ($end === null || $this->at($o)->lt($end)),
        );
        $sum = fn (Collection $set): array => [
            'sales' => round((float) $set->sum('total'), 2),
            'net' => round((float) $set->sum(fn (Order $o) => $this->net($o, $settlements)), 2),
            'orders' => $set->count(),
        ];

        $todayStart = $now->copy()->startOfDay();
        $weekStart = $now->copy()->subDays(6)->startOfDay();
        $monthStart = $now->copy()->startOfMonth();

        $data = [
            'plan' => $entitlement['plan'] ?? 'free',
            'features' => ['insights' => $insights, 'pro' => $pro],
            'unlock' => [
                'insights' => $this->planFor('sales_insights'),
                'pro' => $this->planFor('sales_pro'),
            ],
            'summary' => [
                'today' => $sum($window($todayStart)),
                'week' => $sum($window($weekStart)),
                'month' => $sum($window($monthStart)),
            ],
            'recent' => $orders->sortByDesc(fn (Order $o) => $this->at($o)->timestamp)->take(30)->values()
                ->map(fn (Order $o): array => $this->orderRow($o, $settlements))->all(),
            'insights' => null,
            'pro' => null,
        ];

        if ($insights) {
            $last30 = $window($now->copy()->subDays(29)->startOfDay());
            $daily = [];
            for ($i = 29; $i >= 0; $i--) {
                $day = $now->copy()->subDays($i)->startOfDay();
                $set = $last30->filter(fn (Order $o): bool => $this->at($o)->isSameDay($day));
                $daily[] = ['date' => $day->toDateString(), 'sales' => round((float) $set->sum('total'), 2), 'orders' => $set->count()];
            }
            $hours = array_fill(0, 24, 0);
            $days = array_fill(0, 7, 0);
            foreach ($last30 as $o) {
                $placed = ($o->created_at ?? $o->delivered_at)->copy()->timezone(self::TZ);
                $hours[(int) $placed->format('G')]++;
                $days[(int) $placed->format('w')]++;
            }
            $data['insights'] = [
                'daily' => $daily,
                'top_products' => $this->topProducts($last30),
                'peak_hours' => collect($hours)->map(fn (int $n, int $h): array => ['hour' => $h, 'orders' => $n])->values()->all(),
                'peak_days' => collect($days)->map(fn (int $n, int $d): array => ['day' => $d, 'label' => self::WEEKDAYS[$d], 'orders' => $n])->values()->all(),
            ];
        }

        if ($pro) {
            $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            // Same number of days into last month, so the comparison is fair mid-month.
            $lastMonthCut = $lastMonthStart->copy()->addDays($now->day - 1)->endOfDay()->min($monthStart);
            $thisMonth = $sum($window($monthStart));
            $lastMonth = $sum($window($lastMonthStart, $lastMonthCut));
            $thisWeek = $sum($window($weekStart));
            $lastWeek = $sum($window($now->copy()->subDays(13)->startOfDay(), $weekStart));
            $data['pro'] = [
                'comparison' => [
                    'this_month' => $thisMonth, 'last_month_same_days' => $lastMonth,
                    'month_change_pct' => $this->change($thisMonth['sales'], $lastMonth['sales']),
                    'this_week' => $thisWeek, 'last_week' => $lastWeek,
                    'week_change_pct' => $this->change($thisWeek['sales'], $lastWeek['sales']),
                ],
                'customers' => $this->customers($window($now->copy()->subDays(29)->startOfDay())),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Sales file for the family's accountant and tax return. Gold (sales_pro) only. */
    public function export(Request $request): JsonResponse
    {
        $store = $this->familyStore($request);
        $capabilities = $this->storefront->catalogEntitlement($store->productiveFamily)['capabilities'] ?? [];
        abort_if(empty($capabilities['sales_pro']), 403, 'تنزيل ملف المبيعات متاح في الباقة الذهبية.');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::createFromFormat('Y-m-d', $data['from'], self::TZ)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $data['to'], self::TZ)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'أقصى فترة للتنزيل سنة واحدة.');

        $orders = $this->completedOrders($store, $from, $to)->sortBy(fn (Order $o) => $this->at($o)->timestamp)->values();
        $settlements = $this->settlements($orders);
        $items = OrderItem::query()->whereIn('order_id', $orders->pluck('id'))->get(['order_id', 'product_name', 'quantity'])
            ->groupBy('order_id');

        $rows = [['رقم الطلب', 'التاريخ', 'الوقت', 'الأصناف', 'قيمة الأصناف', 'رسوم التوصيل', 'الخصم', 'إجمالي الطلب', 'عمولة زاد سينك', 'صافي الأسرة', 'حالة التسوية']];
        $totals = ['subtotal' => 0.0, 'delivery' => 0.0, 'discount' => 0.0, 'total' => 0.0, 'commission' => 0.0, 'net' => 0.0];
        foreach ($orders as $o) {
            $at = $this->at($o);
            $settlement = $settlements->get($o->id);
            $commission = (float) ($settlement?->family_commission ?? 0);
            $net = $this->net($o, $settlements);
            $line = ($items->get($o->id) ?? collect())->map(fn (OrderItem $i): string => $i->product_name.' × '.$i->quantity)->implode('، ');
            $rows[] = [
                $o->number, $at->format('Y-m-d'), $at->format('H:i'), $line,
                $this->money($o->subtotal), $this->money($o->delivery_fee), $this->money($o->discount), $this->money($o->total),
                $this->money($commission), $this->money($net), $settlement?->status ?? '—',
            ];
            $totals['subtotal'] += (float) $o->subtotal;
            $totals['delivery'] += (float) $o->delivery_fee;
            $totals['discount'] += (float) $o->discount;
            $totals['total'] += (float) $o->total;
            $totals['commission'] += $commission;
            $totals['net'] += $net;
        }
        $rows[] = [];
        $rows[] = ['الإجمالي', '', '', $orders->count().' طلب', $this->money($totals['subtotal']), $this->money($totals['delivery']),
            $this->money($totals['discount']), $this->money($totals['total']), $this->money($totals['commission']), $this->money($totals['net']), ''];
        $rows[] = [];
        $rows[] = ['المتجر', (string) $store->name_ar];
        $rows[] = ['الفترة', $from->toDateString().' إلى '.$to->toDateString()];
        $rows[] = ['ملاحظة', 'ملف مساعد لمحاسبك وإقرارك؛ لا يُرسل تلقائياً لهيئة الزكاة والضريبة والجمارك.'];

        $csv = "\xEF\xBB\xBF".collect($rows)->map(fn (array $row): string => collect($row)->map(
            fn ($cell): string => '"'.str_replace('"', '""', (string) $cell).'"',
        )->implode(','))->implode("\r\n");

        return response()->json([
            'filename' => 'zad-sales-'.$from->toDateString().'-to-'.$to->toDateString().'.csv',
            'mime' => 'text/csv',
            'orders' => $orders->count(),
            'content_base64' => base64_encode($csv),
        ]);
    }

    private function completedOrders(Store $store, Carbon $from, Carbon $to): Collection
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->whereIn('status', Order::completedStatuses())
            ->where('payment_status', Order::PAYMENT_PAID)
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('delivered_at', [$from->copy()->utc(), $to->copy()->utc()])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('delivered_at')
                        ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()]));
            })
            ->get(['id', 'number', 'customer_id', 'contact_phone', 'subtotal', 'delivery_fee', 'discount', 'total', 'created_at', 'delivered_at']);
    }

    private function settlements(Collection $orders): Collection
    {
        return OrderSettlement::query()->whereIn('order_id', $orders->pluck('id'))
            ->get(['order_id', 'family_commission', 'family_net', 'status'])->keyBy('order_id');
    }

    private function at(Order $o): Carbon
    {
        return ($o->delivered_at ?? $o->created_at)->copy()->timezone(self::TZ);
    }

    private function net(Order $o, Collection $settlements): float
    {
        $settlement = $settlements->get($o->id);
        // Before settlement, the family's share is the food value after any discount.
        return $settlement ? (float) $settlement->family_net : max(0, (float) $o->subtotal - (float) $o->discount);
    }

    private function orderRow(Order $o, Collection $settlements): array
    {
        $settlement = $settlements->get($o->id);

        return [
            'id' => $o->id,
            'number' => $o->number,
            'at' => $this->at($o)->toIso8601String(),
            'total' => round((float) $o->total, 2),
            'commission' => round((float) ($settlement?->family_commission ?? 0), 2),
            'net' => round($this->net($o, $settlements), 2),
            'settlement_status' => $settlement?->status,
        ];
    }

    private function topProducts(Collection $orders): array
    {
        return OrderItem::query()->whereIn('order_id', $orders->pluck('id'))->get(['product_id', 'product_name', 'quantity', 'total'])
            ->groupBy(fn (OrderItem $i): string => (string) ($i->product_id ?? $i->product_name))
            ->map(fn (Collection $group): array => [
                'name' => (string) $group->first()->product_name,
                'quantity' => (int) $group->sum('quantity'),
                'sales' => round((float) $group->sum('total'), 2),
            ])
            ->sortByDesc('quantity')->take(5)->values()->all();
    }

    private function customers(Collection $orders): array
    {
        $groups = $orders->groupBy(fn (Order $o): string => $o->customer_id ? 'c'.$o->customer_id : 'p'.preg_replace('/\D/', '', (string) $o->contact_phone))
            ->reject(fn ($group, string $key): bool => $key === 'p');
        $names = Customer::query()->whereIn('id', $orders->pluck('customer_id')->filter()->unique())->pluck('name', 'id');
        $top = $groups->map(function (Collection $group) use ($names): array {
            $first = $group->first();
            $phone = preg_replace('/\D/', '', (string) $first->contact_phone);
            $name = $first->customer_id ? trim((string) ($names[$first->customer_id] ?? '')) : '';
            // Privacy: first name only and the last two digits of the phone.
            $label = ($name !== '' ? explode(' ', $name)[0] : 'عميل').($phone !== '' ? ' ••'.substr($phone, -2) : '');

            return ['label' => $label, 'orders' => $group->count(), 'sales' => round((float) $group->sum('total'), 2)];
        })->sortByDesc('orders')->take(5)->values()->all();

        return [
            'unique' => $groups->count(),
            'returning' => $groups->filter(fn (Collection $g): bool => $g->count() > 1)->count(),
            'top' => $top,
        ];
    }

    private function change(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function planFor(string $capability): ?string
    {
        foreach (['free', 'silver', 'gold'] as $code) {
            $plan = \App\Models\FamilyStorePlan::query()->where('code', $code)->first();
            if ($plan && ! empty(($plan->capabilities ?? [])[$capability])) {
                return $code;
            }
        }

        return null;
    }

    private function familyStore(Request $request): Store
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->first();
        abort_unless($profile?->productive_family_id !== null, 403, 'هذا الحساب غير مرتبط بأسرة منتجة.');

        $store = Store::query()->with('productiveFamily')->where('productive_family_id', $profile->productive_family_id)->first();
        abort_unless($store !== null, 404, 'لم يتم العثور على متجر الأسرة.');

        return $store;
    }
}
