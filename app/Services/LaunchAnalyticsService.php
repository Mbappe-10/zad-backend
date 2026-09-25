<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LaunchAnalyticsService
{
    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'campaign_id' => ['nullable', 'integer', 'exists:launch_campaigns,id'],
            'creator_id' => ['nullable', 'integer', 'exists:launch_creators,id'],
            'family_id' => ['nullable', 'integer', 'exists:productive_families,id'],
            'booth' => ['nullable', 'string', 'max:80'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'source' => ['nullable', 'string', 'max:80'],
            'attribution' => ['nullable', 'in:first,last'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        return [
            ...$validated,
            'date_from' => $validated['date_from'] ?? now()->subDays(29)->toDateString(),
            'date_to' => $validated['date_to'] ?? now()->toDateString(),
            'attribution' => $validated['attribution'] ?? 'last',
            'per_page' => (int) ($validated['per_page'] ?? 15),
        ];
    }

    /** @return array<string, int|float> */
    public function summary(array $filters): array
    {
        $key = 'launch:summary:'.sha1(json_encode($this->cacheableFilters($filters)));

        return Cache::remember($key, now()->addSeconds(60), function () use ($filters): array {
            $events = $this->eventQuery($filters);
            $eventSummary = (clone $events)->selectRaw(
                "COUNT(DISTINCT CASE WHEN event.event_type = 'visit' THEN event.id END) AS visits,
                 COUNT(DISTINCT CASE WHEN event.event_type = 'qr_scan' THEN event.id END) AS qr_scans,
                 COUNT(DISTINCT CASE WHEN event.event_type = 'signup' THEN event.user_id END) AS signups",
            )->first();

            $orders = $this->successfulOrders($filters);
            $orderSummary = (clone $orders)->selectRaw(
                'COUNT(*) AS total_orders,
                 COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv,
                 COALESCE(SUM('.$this->revenueExpression().'), 0) AS zad_revenue,
                 COALESCE(SUM(attribution.creator_commission_amount), 0) AS creator_commission',
            )->first();
            $firstOrders = $this->firstOrders($filters)->count();
            $repeatCustomers = $this->repeatCustomerCount($filters);
            $newUsers = (clone $events)
                ->whereNotNull('event.user_id')
                ->distinct('event.user_id')
                ->count('event.user_id');
            $newFamilies = DB::table('launch_campaign_families as assignment')
                ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.campaign_id', $value))
                ->when($filters['family_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.family_id', $value))
                ->whereDate('assignment.created_at', '>=', $filters['date_from'])
                ->whereDate('assignment.created_at', '<=', $filters['date_to'])
                ->distinct('assignment.family_id')
                ->count('assignment.family_id');

            $visits = (int) ($eventSummary->visits ?? 0);
            $totalOrders = (int) ($orderSummary->total_orders ?? 0);
            $gmv = round((float) ($orderSummary->gmv ?? 0), 2);

            return [
                'visits' => $visits,
                'qr_scans' => (int) ($eventSummary->qr_scans ?? 0),
                'new_users' => $newUsers,
                'new_families' => $newFamilies,
                'first_orders' => $firstOrders,
                'orders' => $totalOrders,
                'gmv' => $gmv,
                'zad_revenue' => round((float) ($orderSummary->zad_revenue ?? 0), 2),
                'creator_commission' => round((float) ($orderSummary->creator_commission ?? 0), 2),
                'conversion_rate' => $visits > 0 ? round(($totalOrders / $visits) * 100, 2) : 0.0,
                'repeat_customers' => $repeatCustomers,
                'average_order_value' => $totalOrders > 0 ? round($gmv / $totalOrders, 2) : 0.0,
            ];
        });
    }

    /** @return array<int, array<string, int|float|string>> */
    public function timeseries(array $filters): array
    {
        $key = 'launch:timeseries:'.sha1(json_encode($this->cacheableFilters($filters)));

        return Cache::remember($key, now()->addSeconds(60), function () use ($filters): array {
            $eventRows = $this->eventQuery($filters)
                ->selectRaw("DATE(event.occurred_at) AS metric_date")
                ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'visit' THEN event.id END) AS visits")
                ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'signup' THEN event.user_id END) AS signups")
                ->groupByRaw('DATE(event.occurred_at)')
                ->get()
                ->keyBy('metric_date');
            $orderRows = $this->successfulOrders($filters)
                ->selectRaw('DATE(orders.created_at) AS metric_date')
                ->selectRaw('COUNT(*) AS orders')
                ->selectRaw('COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv')
                ->selectRaw('COALESCE(SUM('.$this->revenueExpression().'), 0) AS revenue')
                ->groupByRaw('DATE(orders.created_at)')
                ->get()
                ->keyBy('metric_date');
            $rows = [];
            $date = CarbonImmutable::parse($filters['date_from'])->startOfDay();
            $last = CarbonImmutable::parse($filters['date_to'])->startOfDay();

            while ($date->lte($last)) {
                $key = $date->toDateString();
                $event = $eventRows->get($key);
                $order = $orderRows->get($key);
                $rows[] = [
                    'date' => $key,
                    'visits' => (int) ($event->visits ?? 0),
                    'signups' => (int) ($event->signups ?? 0),
                    'orders' => (int) ($order->orders ?? 0),
                    'gmv' => round((float) ($order->gmv ?? 0), 2),
                    'revenue' => round((float) ($order->revenue ?? 0), 2),
                ];
                $date = $date->addDay();
            }

            return $rows;
        });
    }

    /** @return array<int, array{stage: string, value: int}> */
    public function funnel(array $filters): array
    {
        $key = 'launch:funnel:'.sha1(json_encode($this->cacheableFilters($filters)));

        return Cache::remember($key, now()->addSeconds(60), function () use ($filters): array {
            $counts = $this->eventQuery($filters)
                ->whereIn('event.event_type', ['visit', 'store_view', 'product_view', 'add_to_cart', 'checkout'])
                ->select('event.event_type')
                ->selectRaw('COUNT(DISTINCT event.visitor_hash) AS aggregate')
                ->groupBy('event.event_type')
                ->pluck('aggregate', 'event_type');
            $paidOrders = $this->successfulOrders($filters)->count();

            return collect([
                'visit' => 'Visit',
                'store_view' => 'Store View',
                'product_view' => 'Product View',
                'add_to_cart' => 'Add To Cart',
                'checkout' => 'Checkout',
                'paid_order' => 'Paid Order',
            ])->map(fn (string $label, string $type): array => [
                'stage' => $label,
                'value' => $type === 'paid_order'
                    ? $paidOrders
                    : (int) ($counts[$type] ?? 0),
            ])->values()->all();
        });
    }

    public function creators(array $filters): LengthAwarePaginator
    {
        $paginator = DB::table('launch_campaign_creators as assignment')
            ->join('launch_creators as creator', 'creator.id', '=', 'assignment.creator_id')
            ->join('launch_campaigns as campaign', 'campaign.id', '=', 'assignment.campaign_id')
            ->whereNull('creator.deleted_at')
            ->whereNull('campaign.deleted_at')
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.campaign_id', $value))
            ->when($filters['creator_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.creator_id', $value))
            ->when($filters['city_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('campaign.city_id', $value))
            ->when($filters['search'] ?? null, function (Builder $query, mixed $value): void {
                $like = '%'.mb_strtolower(trim((string) $value)).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereRaw("LOWER(creator.name) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE(creator.handle, '')) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(campaign.name) LIKE ?", [$like]);
                });
            })
            ->select([
                'assignment.id',
                'assignment.campaign_id',
                'assignment.creator_id',
                'assignment.tracking_code',
                'assignment.commission_type',
                'assignment.commission_value',
                'assignment.status',
                'assignment.contract_reference',
                'assignment.contract_version',
                'assignment.contract_title',
                'assignment.contract_content',
                'assignment.contract_status',
                'assignment.contract_starts_at',
                'assignment.contract_ends_at',
                'assignment.contract_cancelled_at',
                'assignment.contract_updated_at',
                'assignment.coupon_code',
                'creator.code as creator_code',
                'creator.name as creator_name',
                'creator.handle as creator_handle',
                'campaign.name as campaign_name',
            ])
            ->orderBy('creator.name')
            ->paginate($filters['per_page']);

        return $this->hydrateCreatorMetrics($paginator, $filters);
    }

    public function families(array $filters): LengthAwarePaginator
    {
        $paginator = DB::table('launch_campaign_families as assignment')
            ->join('productive_families as family', 'family.id', '=', 'assignment.family_id')
            ->join('launch_campaigns as campaign', 'campaign.id', '=', 'assignment.campaign_id')
            ->leftJoin('stores as store', function ($join): void {
                $join->on('store.productive_family_id', '=', 'family.id')->whereNull('store.deleted_at');
            })
            ->whereNull('family.deleted_at')
            ->whereNull('campaign.deleted_at')
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.campaign_id', $value))
            ->when($filters['family_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.family_id', $value))
            ->when($filters['booth'] ?? null, fn (Builder $query, mixed $value) => $query->where('assignment.booth_code', $value))
            ->when($filters['city_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('campaign.city_id', $value))
            ->when($filters['search'] ?? null, function (Builder $query, mixed $value): void {
                $like = '%'.mb_strtolower(trim((string) $value)).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereRaw("LOWER(family.owner_name) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE(store.name_ar, '')) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE(assignment.booth_code, '')) LIKE ?", [$like]);
                });
            })
            ->select([
                'assignment.id',
                'assignment.campaign_id',
                'assignment.family_id',
                'assignment.booth_code',
                'assignment.tracking_code',
                'assignment.status',
                'family.owner_name as family_name',
                'family.code as family_code',
                'store.id as store_id',
                'store.name_ar as store_name',
                'campaign.name as campaign_name',
            ])
            ->orderBy('family.owner_name')
            ->paginate($filters['per_page']);

        return $this->hydrateFamilyMetrics($paginator, $filters);
    }

    public function campaigns(array $filters): LengthAwarePaginator
    {
        $paginator = DB::table('launch_campaigns as campaign')
            ->leftJoin('cities as city', 'city.id', '=', 'campaign.city_id')
            ->whereNull('campaign.deleted_at')
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('campaign.id', $value))
            ->when($filters['city_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('campaign.city_id', $value))
            ->when($filters['search'] ?? null, function (Builder $query, mixed $value): void {
                $like = '%'.mb_strtolower(trim((string) $value)).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereRaw('LOWER(campaign.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(campaign.code) LIKE ?', [$like]);
                });
            })
            ->select([
                'campaign.*',
                'city.name_ar as city_name_ar',
                'city.name_en as city_name_en',
            ])
            ->orderByDesc('campaign.starts_at')
            ->paginate($filters['per_page']);

        return $this->hydrateCampaignMetrics($paginator, $filters);
    }

    /** @return array<string, mixed> */
    public function realtime(array $filters): array
    {
        $todayFilters = [
            ...$filters,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'per_page' => 5,
        ];
        $key = 'launch:realtime:'.sha1(json_encode($this->cacheableFilters($todayFilters)));

        return Cache::remember($key, now()->addSeconds(10), function () use ($todayFilters): array {
            $summary = $this->summaryUncached($todayFilters);
            $activeVisitors = $this->eventQuery($todayFilters)
                ->where('event.occurred_at', '>=', now()->subMinutes(5))
                ->distinct('event.visitor_hash')
                ->count('event.visitor_hash');
            $families = $this->topFamilies($todayFilters);
            $creators = $this->topCreators($todayFilters);
            $latestOrders = $this->successfulOrders($todayFilters)
                ->leftJoin('customers as customer', 'customer.id', '=', 'orders.customer_id')
                ->leftJoin('stores as store', 'store.id', '=', 'orders.store_id')
                ->select([
                    'orders.id',
                    'orders.number',
                    'orders.total',
                    'orders.created_at',
                    'customer.name as customer_name',
                    'store.name_ar as store_name',
                ])
                ->orderByDesc('orders.created_at')
                ->limit(10)
                ->get();

            return [
                'active_visitors' => $activeVisitors,
                'today' => $summary,
                'top_families' => $families,
                'top_creators' => $creators,
                'latest_orders' => $latestOrders,
                'generated_at' => now()->toIso8601String(),
                'poll_after_seconds' => 15,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return Cache::remember('launch:options', now()->addMinutes(5), fn (): array => [
            'campaigns' => DB::table('launch_campaigns')->whereNull('deleted_at')->orderByDesc('starts_at')->get(['id', 'name', 'code', 'status']),
            'creators' => DB::table('launch_creators')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'code', 'handle']),
            'families' => DB::table('productive_families')->whereNull('deleted_at')->orderBy('owner_name')->get(['id', 'owner_name as name', 'code']),
            'cities' => DB::table('cities')->whereNull('deleted_at')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'booths' => DB::table('launch_campaign_families')->whereNotNull('booth_code')->distinct()->orderBy('booth_code')->pluck('booth_code'),
            'sources' => DB::table('launch_events')->whereNotNull('source')->distinct()->orderBy('source')->limit(100)->pluck('source'),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function exportRows(string $type, array $filters): Collection
    {
        $exportFilters = [...$filters, 'per_page' => 100];
        $page = 1;
        $rows = collect();

        do {
            Paginator::currentPageResolver(fn (): int => $page);
            $paginator = match ($type) {
                'families' => $this->families($exportFilters),
                'campaigns' => $this->campaigns($exportFilters),
                default => $this->creators($exportFilters),
            };
            $rows->push(...$paginator->items());
            $page++;
        } while ($paginator->hasMorePages() && $rows->count() < 10000);

        Paginator::currentPageResolver(fn (): int => 1);

        return $rows->take(10000)->values();
    }

    private function hydrateCreatorMetrics(LengthAwarePaginator $paginator, array $filters): LengthAwarePaginator
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';
        $rows = collect($paginator->items());
        $creatorIds = $rows->pluck('creator_id')->unique()->values();
        $campaignIds = $rows->pluck('campaign_id')->unique()->values();
        $events = $this->eventQuery($filters)
            ->whereIn('event.creator_id', $creatorIds)
            ->whereIn('event.campaign_id', $campaignIds)
            ->select(['event.creator_id', 'event.campaign_id'])
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'qr_scan' THEN event.id END) AS qr_scans")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'visit' THEN event.visitor_hash END) AS unique_visitors")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'signup' THEN event.user_id END) AS signups")
            ->groupBy('event.creator_id', 'event.campaign_id')
            ->get()
            ->keyBy(fn (object $row): string => $row->creator_id.'|'.$row->campaign_id);
        $orders = $this->successfulOrders($filters)
            ->whereIn("attribution.{$touch}_creator_id", $creatorIds)
            ->whereIn("attribution.{$touch}_campaign_id", $campaignIds)
            ->selectRaw("attribution.{$touch}_creator_id AS creator_id")
            ->selectRaw("attribution.{$touch}_campaign_id AS campaign_id")
            ->selectRaw('COUNT(*) AS total_orders')
            ->selectRaw('COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv')
            ->selectRaw('COALESCE(SUM('.$this->revenueExpression().'), 0) AS zad_revenue')
            ->selectRaw('COALESCE(SUM(attribution.creator_commission_amount), 0) AS creator_commission')
            ->groupBy("attribution.{$touch}_creator_id", "attribution.{$touch}_campaign_id")
            ->get()
            ->keyBy(fn (object $row): string => $row->creator_id.'|'.$row->campaign_id);
        $firstOrders = $this->firstOrders($filters)
            ->whereIn("attribution.{$touch}_creator_id", $creatorIds)
            ->whereIn("attribution.{$touch}_campaign_id", $campaignIds)
            ->selectRaw("attribution.{$touch}_creator_id AS creator_id")
            ->selectRaw("attribution.{$touch}_campaign_id AS campaign_id")
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy("attribution.{$touch}_creator_id", "attribution.{$touch}_campaign_id")
            ->get()
            ->keyBy(fn (object $row): string => $row->creator_id.'|'.$row->campaign_id);
        $repeats = $this->repeatCountsBy($filters, 'creator');

        $paginator->setCollection($rows->map(function (object $row) use ($events, $orders, $firstOrders, $repeats): array {
            $key = $row->creator_id.'|'.$row->campaign_id;
            $event = $events->get($key);
            $order = $orders->get($key);
            $visitors = (int) ($event->unique_visitors ?? 0);
            $totalOrders = (int) ($order->total_orders ?? 0);

            return [
                ...(array) $row,
                'qr_scans' => (int) ($event->qr_scans ?? 0),
                'unique_visitors' => $visitors,
                'signups' => (int) ($event->signups ?? 0),
                'first_orders' => (int) ($firstOrders->get($key)?->aggregate ?? 0),
                'total_orders' => $totalOrders,
                'gmv' => round((float) ($order->gmv ?? 0), 2),
                'zad_revenue' => round((float) ($order->zad_revenue ?? 0), 2),
                'creator_commission' => round((float) ($order->creator_commission ?? 0), 2),
                'conversion_rate' => $visitors > 0 ? round(($totalOrders / $visitors) * 100, 2) : 0,
                'repeat_customers' => (int) ($repeats[$key] ?? 0),
                'referral_url' => route('launch.redirect', ['code' => $row->tracking_code]),
            ];
        }));

        return $paginator;
    }

    private function hydrateFamilyMetrics(LengthAwarePaginator $paginator, array $filters): LengthAwarePaginator
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';
        $rows = collect($paginator->items());
        $familyIds = $rows->pluck('family_id')->unique()->values();
        $campaignIds = $rows->pluck('campaign_id')->unique()->values();
        $events = $this->eventQuery($filters)
            ->whereIn('event.family_id', $familyIds)
            ->whereIn('event.campaign_id', $campaignIds)
            ->select(['event.family_id', 'event.campaign_id'])
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'store_view' THEN event.id END) AS store_visits")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'qr_scan' THEN event.id END) AS qr_scans")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'product_view' THEN event.id END) AS product_views")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'add_to_cart' THEN event.id END) AS add_to_cart")
            ->groupBy('event.family_id', 'event.campaign_id')
            ->get()
            ->keyBy(fn (object $row): string => $row->family_id.'|'.$row->campaign_id);
        $orders = $this->successfulOrders($filters)
            ->whereIn('attribution.family_id', $familyIds)
            ->whereIn("attribution.{$touch}_campaign_id", $campaignIds)
            ->select('attribution.family_id')
            ->selectRaw("attribution.{$touch}_campaign_id AS campaign_id")
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv')
            ->selectRaw('COALESCE(SUM('.$this->revenueExpression().'), 0) AS zad_revenue')
            ->groupBy('attribution.family_id', "attribution.{$touch}_campaign_id")
            ->get()
            ->keyBy(fn (object $row): string => $row->family_id.'|'.$row->campaign_id);

        $paginator->setCollection($rows->map(function (object $row) use ($events, $orders): array {
            $key = $row->family_id.'|'.$row->campaign_id;
            $event = $events->get($key);
            $order = $orders->get($key);
            $visits = (int) ($event->store_visits ?? 0);
            $totalOrders = (int) ($order->orders ?? 0);

            return [
                ...(array) $row,
                'store_visits' => $visits,
                'qr_scans' => (int) ($event->qr_scans ?? 0),
                'product_views' => (int) ($event->product_views ?? 0),
                'add_to_cart' => (int) ($event->add_to_cart ?? 0),
                'orders' => $totalOrders,
                'gmv' => round((float) ($order->gmv ?? 0), 2),
                'zad_revenue' => round((float) ($order->zad_revenue ?? 0), 2),
                'conversion_rate' => $visits > 0 ? round(($totalOrders / $visits) * 100, 2) : 0,
                'store_url' => $row->store_id
                    ? rtrim((string) config('app.frontend_url'), '/').'/stores/'.$row->store_id.'?source=launch&campaign='.$row->campaign_id.'&booth='.urlencode((string) $row->booth_code)
                    : null,
                'referral_url' => route('launch.redirect', ['code' => $row->tracking_code]),
            ];
        }));

        return $paginator;
    }

    private function hydrateCampaignMetrics(LengthAwarePaginator $paginator, array $filters): LengthAwarePaginator
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';
        $rows = collect($paginator->items());
        $ids = $rows->pluck('id')->unique()->values();
        $events = $this->eventQuery($filters)
            ->whereIn('event.campaign_id', $ids)
            ->select('event.campaign_id')
            ->selectRaw("COUNT(DISTINCT CASE WHEN event.event_type = 'visit' THEN event.id END) AS visits")
            ->groupBy('event.campaign_id')
            ->get()->keyBy('campaign_id');
        $orders = $this->successfulOrders($filters)
            ->whereIn("attribution.{$touch}_campaign_id", $ids)
            ->selectRaw("attribution.{$touch}_campaign_id AS campaign_id")
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv')
            ->selectRaw('COALESCE(SUM('.$this->revenueExpression().'), 0) AS zad_revenue')
            ->groupBy("attribution.{$touch}_campaign_id")
            ->get()->keyBy('campaign_id');
        $families = DB::table('launch_campaign_families')->whereIn('campaign_id', $ids)
            ->select('campaign_id')->selectRaw('COUNT(*) AS aggregate')->groupBy('campaign_id')->pluck('aggregate', 'campaign_id');
        $creators = DB::table('launch_campaign_creators')->whereIn('campaign_id', $ids)
            ->select('campaign_id')->selectRaw('COUNT(*) AS aggregate')->groupBy('campaign_id')->pluck('aggregate', 'campaign_id');

        $paginator->setCollection($rows->map(function (object $row) use ($events, $orders, $families, $creators): array {
            $event = $events->get($row->id);
            $order = $orders->get($row->id);

            return [
                ...(array) $row,
                'families_count' => (int) ($families[$row->id] ?? 0),
                'creators_count' => (int) ($creators[$row->id] ?? 0),
                'visits' => (int) ($event->visits ?? 0),
                'orders' => (int) ($order->orders ?? 0),
                'gmv' => round((float) ($order->gmv ?? 0), 2),
                'zad_revenue' => round((float) ($order->zad_revenue ?? 0), 2),
                'referral_url' => route('launch.redirect', ['code' => $row->tracking_code]),
            ];
        }));

        return $paginator;
    }

    private function eventQuery(array $filters): Builder
    {
        return DB::table('launch_events as event')
            ->leftJoin('launch_campaigns as campaign', 'campaign.id', '=', 'event.campaign_id')
            ->whereDate('event.occurred_at', '>=', $filters['date_from'])
            ->whereDate('event.occurred_at', '<=', $filters['date_to'])
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('event.campaign_id', $value))
            ->when($filters['creator_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('event.creator_id', $value))
            ->when($filters['family_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('event.family_id', $value))
            ->when($filters['booth'] ?? null, fn (Builder $query, mixed $value) => $query->where('event.booth', $value))
            ->when($filters['city_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('campaign.city_id', $value))
            ->when($filters['source'] ?? null, fn (Builder $query, mixed $value) => $query->where('event.source', $value));
    }

    private function successfulOrders(array $filters): Builder
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';
        $payments = DB::table('payments')
            ->where('status', 'paid')
            ->select('order_id')
            ->selectRaw('SUM(provider_fee) AS provider_fee')
            ->groupBy('order_id');
        $refunds = DB::table('refunds')
            ->whereIn('status', ['approved', 'completed'])
            ->select('order_id')
            ->selectRaw('SUM(amount) AS refunded_amount')
            ->groupBy('order_id');
        $platformCommission = DB::table('order_commissions')
            ->whereIn('beneficiary_type', ['platform', 'zad', 'zad_sync', 'platform_owner'])
            ->whereNotIn('status', ['cancelled', 'reversed', 'rejected'])
            ->select('order_id')
            ->selectRaw('SUM(commission_amount) AS platform_revenue')
            ->groupBy('order_id');

        return DB::table('launch_order_attributions as attribution')
            ->join('orders', 'orders.id', '=', 'attribution.order_id')
            ->leftJoinSub($payments, 'payment_total', fn ($join) => $join->on('payment_total.order_id', '=', 'orders.id'))
            ->leftJoinSub($refunds, 'refund_total', fn ($join) => $join->on('refund_total.order_id', '=', 'orders.id'))
            ->leftJoinSub($platformCommission, 'platform_total', fn ($join) => $join->on('platform_total.order_id', '=', 'orders.id'))
            ->whereNull('orders.deleted_at')
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.status', ['cancelled', 'rejected'])
            ->whereDate('orders.created_at', '>=', $filters['date_from'])
            ->whereDate('orders.created_at', '<=', $filters['date_to'])
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, mixed $value) => $query->where("attribution.{$touch}_campaign_id", $value))
            ->when($filters['creator_id'] ?? null, fn (Builder $query, mixed $value) => $query->where("attribution.{$touch}_creator_id", $value))
            ->when($filters['family_id'] ?? null, function (Builder $query, mixed $value) use ($touch): void {
                $query->where(function (Builder $query) use ($touch, $value): void {
                    $query->where('attribution.family_id', $value)
                        ->orWhere("attribution.{$touch}_family_id", $value);
                });
            })
            ->when($filters['booth'] ?? null, fn (Builder $query, mixed $value) => $query->where("attribution.{$touch}_booth", $value))
            ->when($filters['city_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('orders.city_id', $value))
            ->when($filters['source'] ?? null, fn (Builder $query, mixed $value) => $query->where("attribution.{$touch}_source", $value));
    }

    /** @return array<int, array<string, mixed>> */
    private function topFamilies(array $filters): array
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';

        return $this->successfulOrders($filters)
            ->join('productive_families as family', 'family.id', '=', 'attribution.family_id')
            ->leftJoin('launch_campaigns as selected_campaign', 'selected_campaign.id', '=', "attribution.{$touch}_campaign_id")
            ->select([
                'family.id as family_id',
                'family.owner_name as family_name',
                'selected_campaign.id as campaign_id',
                'selected_campaign.name as campaign_name',
            ])
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv')
            ->selectRaw('COALESCE(SUM('.$this->revenueExpression().'), 0) AS zad_revenue')
            ->groupBy('family.id', 'family.owner_name', 'selected_campaign.id', 'selected_campaign.name')
            ->orderByDesc('gmv')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                ...(array) $row,
                'orders' => (int) $row->orders,
                'gmv' => round((float) $row->gmv, 2),
                'zad_revenue' => round((float) $row->zad_revenue, 2),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topCreators(array $filters): array
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';

        return $this->successfulOrders($filters)
            ->join('launch_creators as selected_creator', 'selected_creator.id', '=', "attribution.{$touch}_creator_id")
            ->leftJoin('launch_campaigns as selected_campaign', 'selected_campaign.id', '=', "attribution.{$touch}_campaign_id")
            ->select([
                'selected_creator.id as creator_id',
                'selected_creator.name as creator_name',
                'selected_campaign.id as campaign_id',
                'selected_campaign.name as campaign_name',
            ])
            ->selectRaw('COUNT(*) AS total_orders')
            ->selectRaw('COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv')
            ->selectRaw('COALESCE(SUM(attribution.creator_commission_amount), 0) AS creator_commission')
            ->groupBy('selected_creator.id', 'selected_creator.name', 'selected_campaign.id', 'selected_campaign.name')
            ->orderByDesc('gmv')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                ...(array) $row,
                'total_orders' => (int) $row->total_orders,
                'gmv' => round((float) $row->gmv, 2),
                'creator_commission' => round((float) $row->creator_commission, 2),
            ])
            ->all();
    }

    private function repeatCustomerCount(array $filters): int
    {
        return DB::query()->fromSub(
            $this->successfulOrders($filters)
                ->selectRaw($this->identityExpression().' AS identity_key')
                ->groupByRaw($this->identityExpression())
                ->havingRaw('COUNT(*) > 1'),
            'repeat_customers',
        )->count();
    }

    /**
     * Successful attributed orders that are the customer's first successful
     * order on the whole platform, not merely their first row in this filter.
     */
    private function firstOrders(array $filters): Builder
    {
        return $this->successfulOrders($filters)
            ->whereNotExists(function (Builder $previous): void {
                $previous->selectRaw('1')
                    ->from('orders as previous_order')
                    ->whereNull('previous_order.deleted_at')
                    ->where('previous_order.payment_status', 'paid')
                    ->whereNotIn('previous_order.status', ['cancelled', 'rejected'])
                    ->where(function (Builder $earlier): void {
                        $earlier->whereColumn('previous_order.created_at', '<', 'orders.created_at')
                            ->orWhere(function (Builder $sameTime): void {
                                $sameTime->whereColumn('previous_order.created_at', '=', 'orders.created_at')
                                    ->whereColumn('previous_order.id', '<', 'orders.id');
                            });
                    })
                    ->where(function (Builder $identity): void {
                        $identity->where(function (Builder $customer): void {
                            $customer->whereNotNull('orders.customer_id')
                                ->whereColumn('previous_order.customer_id', 'orders.customer_id');
                        })->orWhere(function (Builder $guest): void {
                            $guest->whereNull('orders.customer_id')
                                ->whereNotNull('orders.guest_session_id')
                                ->whereNull('previous_order.customer_id')
                                ->whereColumn('previous_order.guest_session_id', 'orders.guest_session_id');
                        });
                    });
            });
    }

    /** @return Collection<string, int> */
    private function repeatCountsBy(array $filters, string $dimension): Collection
    {
        $touch = ($filters['attribution'] ?? 'last') === 'first' ? 'first' : 'last';
        $campaignColumn = "attribution.{$touch}_campaign_id";
        $dimensionColumn = $dimension === 'creator'
            ? "attribution.{$touch}_creator_id"
            : 'attribution.family_id';
        $grouped = $this->successfulOrders($filters)
            ->selectRaw($campaignColumn.' AS campaign_id')
            ->selectRaw($dimensionColumn.' AS dimension_id')
            ->selectRaw($this->identityExpression().' AS identity_key')
            ->groupByRaw($campaignColumn.', '.$dimensionColumn.', '.$this->identityExpression())
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $grouped->groupBy(fn (object $row): string => $row->dimension_id.'|'.$row->campaign_id)
            ->map(fn (Collection $rows): int => $rows->count());
    }

    private function summaryUncached(array $filters): array
    {
        $events = $this->eventQuery($filters)->selectRaw(
            "COUNT(DISTINCT CASE WHEN event.event_type = 'visit' THEN event.id END) AS visits,
             COUNT(DISTINCT CASE WHEN event.event_type = 'qr_scan' THEN event.id END) AS qr_scans,
             COUNT(DISTINCT CASE WHEN event.event_type = 'signup' THEN event.user_id END) AS signups",
        )->first();
        $orders = $this->successfulOrders($filters)->selectRaw(
            'COUNT(*) AS orders, COALESCE(SUM('.$this->gmvExpression().'), 0) AS gmv, COALESCE(SUM('.$this->revenueExpression().'), 0) AS revenue',
        )->first();

        return [
            'visits' => (int) ($events->visits ?? 0),
            'qr_scans' => (int) ($events->qr_scans ?? 0),
            'signups' => (int) ($events->signups ?? 0),
            'orders' => (int) ($orders->orders ?? 0),
            'gmv' => round((float) ($orders->gmv ?? 0), 2),
            'zad_revenue' => round((float) ($orders->revenue ?? 0), 2),
        ];
    }

    private function identityExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CASE WHEN orders.customer_id IS NOT NULL THEN 'customer:' || orders.customer_id ELSE 'guest:' || COALESCE(orders.guest_session_id, orders.id) END";
        }

        return "CASE WHEN orders.customer_id IS NOT NULL THEN CONCAT('customer:', orders.customer_id) ELSE CONCAT('guest:', COALESCE(orders.guest_session_id, orders.id)) END";
    }

    private function gmvExpression(): string
    {
        return '(CASE WHEN orders.total - COALESCE(refund_total.refunded_amount, 0) > 0 THEN orders.total - COALESCE(refund_total.refunded_amount, 0) ELSE 0 END)';
    }

    private function revenueExpression(): string
    {
        return '(COALESCE(platform_total.platform_revenue, 0) - COALESCE(payment_total.provider_fee, 0) - COALESCE(attribution.creator_commission_amount, 0))';
    }

    private function cacheableFilters(array $filters): array
    {
        unset($filters['page'], $filters['per_page'], $filters['search']);

        return $filters;
    }
}
