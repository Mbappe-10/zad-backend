<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DigitalEmployee;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCommission;
use App\Models\PlatformAuditLog;
use App\Models\PlatformRecord;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminResourceController extends Controller
{
    private const RESOURCES = [
        'admins',
        'ads',
        'analytics',
        'autonomous-operations',
        'categories',
        'cities',
        'commissions',
        'content',
        'coupons-offers',
        'customers',
        'decisions',
        'digital-employees',
        'drivers',
        'governance',
        'notifications',
        'payment-gateways',
        'payments',
        'payout-requests',
        'permissions',
        'products',
        'roles',
        'settings',
        'stores',
        'subscriptions',
        'support',
        'users',
        'vehicles',
        'wallets',
        'zones',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $this->guardResource($resource);

        $query = PlatformRecord::query()
            ->where('resource', $resource);

        $this->applyFilters($query, $request);

        $sortDirection = $request->string('direction', 'desc')->lower()->toString() === 'asc'
            ? 'asc'
            : 'desc';

        $query->orderBy('created_at', $sortDirection);

        $perPage = min(max((int) $request->input('per_page', 15), 1), 200);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (PlatformRecord $record): array => $this->flatten($record))
                ->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $this->guardResource($resource);

        $payload = $this->payloadFromRequest($request);
        $status = $this->statusFromPayload($payload);

        if ($resource === 'support' && empty($payload['ticket_number'])) {
            $payload['ticket_number'] = 'ZAD-'.now()->format('ymd-His');
            $payload['messages'] = [];
            $payload['messages_count'] = 0;
        }

        if ($resource === 'notifications' && ($payload['send_now'] ?? false)) {
            $status = 'sent';
            $payload['status'] = 'sent';
            $payload['sent_at'] = now()->toISOString();
        }

        $record = DB::transaction(function () use ($request, $resource, $payload, $status): PlatformRecord {
            $record = PlatformRecord::query()->create([
                'resource' => $resource,
                'external_key' => $this->externalKey($payload),
                'status' => $status,
                'payload' => $payload,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $this->audit($request, $record, 'created', null, $this->flatten($record));

            return $record;
        });

        return response()->json([
            'message' => 'تم إنشاء السجل وحفظه بنجاح.',
            'data' => $this->flatten($record),
        ], 201);
    }

    public function show(string $resource, int $record): JsonResponse
    {
        $item = $this->findRecord($resource, $record);

        return response()->json([
            'data' => $this->flatten($item),
        ]);
    }

    public function update(
        Request $request,
        string $resource,
        int $record,
    ): JsonResponse {
        $item = $this->findRecord($resource, $record);
        $before = $this->flatten($item);
        $payload = array_replace_recursive(
            $item->payload ?? [],
            $this->payloadFromRequest($request),
        );
        $status = $this->statusFromPayload($payload, $item->status);

        DB::transaction(function () use ($request, $item, $payload, $status, $before): void {
            $item->update([
                'external_key' => $this->externalKey($payload, $item->external_key),
                'status' => $status,
                'payload' => $payload,
                'updated_by' => $request->user()?->id,
            ]);

            $this->audit(
                $request,
                $item,
                'updated',
                $before,
                $this->flatten($item->fresh()),
            );
        });

        return response()->json([
            'message' => 'تم تحديث السجل بنجاح.',
            'data' => $this->flatten($item->fresh()),
        ]);
    }

    public function destroy(
        Request $request,
        string $resource,
        int $record,
    ): JsonResponse {
        $item = $this->findRecord($resource, $record);
        $before = $this->flatten($item);

        DB::transaction(function () use ($request, $item, $before): void {
            $item->delete();
            $this->audit($request, $item, 'deleted', $before, null);
        });

        return response()->json([
            'message' => 'تم حذف السجل بنجاح.',
        ]);
    }

    public function action(
        Request $request,
        string $resource,
        int $record,
        string $action,
    ): JsonResponse {
        $item = $this->findRecord($resource, $record);
        $before = $this->flatten($item);

        if (in_array($action, ['duplicate', 'clone'], true)) {
            $copy = $item->replicate([
                'external_key',
                'created_by',
                'updated_by',
            ]);
            $payload = $copy->payload ?? [];
            $payload['status'] = 'draft';
            $payload['code'] = isset($payload['code'])
                ? $payload['code'].'-COPY-'.Str::upper(Str::random(4))
                : null;
            $copy->payload = array_filter(
                $payload,
                static fn (mixed $value): bool => $value !== null,
            );
            $copy->status = 'draft';
            $copy->created_by = $request->user()?->id;
            $copy->updated_by = $request->user()?->id;
            $copy->save();
            $this->audit($request, $copy, 'duplicated', null, $this->flatten($copy));

            return response()->json([
                'message' => 'تم إنشاء نسخة جديدة بنجاح.',
                'data' => $this->flatten($copy),
            ], 201);
        }

        if (in_array($action, [
            'analytics',
            'audit',
            'invoices',
            'payments',
            'transactions',
            'usage',
        ], true)) {
            $relatedRows = $action === 'transactions'
                ? (array) data_get($item->payload, 'transactions', [])
                : [];

            return response()->json([
                'message' => 'تم تحميل البيانات المرتبطة.',
                'data' => $relatedRows,
                $action => $relatedRows,
                'record' => $this->flatten($item),
            ]);
        }

        $payload = $item->payload ?? [];
        $payload = $this->applyActionPayload($payload, $action, $request);
        $status = $this->statusForAction(
            $action,
            (string) ($request->input('status') ?: $item->status),
        );
        $payload['status'] = $status;

        DB::transaction(function () use ($request, $item, $payload, $status, $action, $before): void {
            $item->update([
                'status' => $status,
                'payload' => $payload,
                'updated_by' => $request->user()?->id,
            ]);

            $this->audit(
                $request,
                $item,
                $action,
                $before,
                $this->flatten($item->fresh()),
            );
        });

        return response()->json([
            'message' => 'تم تنفيذ الإجراء بنجاح.',
            'data' => $this->flatten($item->fresh()),
        ]);
    }

    public function collectionAction(
        Request $request,
        string $resource,
        string $action,
    ): JsonResponse {
        $this->guardResource($resource);

        if ($resource === 'commissions' && $action === 'simulate') {
            $orderValue = max((float) $request->input('order_value', 0), 0);
            $rate = 15.0;
            $commission = round($orderValue * ($rate / 100), 2);

            return response()->json([
                'data' => [
                    'rule_id' => null,
                    'rule_name' => 'قاعدة العمولة الافتراضية',
                    'commission_amount' => $commission,
                    'platform_amount' => $commission,
                    'beneficiary_amount' => round($orderValue - $commission, 2),
                    'effective_rate' => $rate,
                ],
            ]);
        }

        return response()->json([
            'message' => 'تم تنفيذ الإجراء بنجاح.',
            'data' => [
                'resource' => $resource,
                'action' => $action,
                'executed_at' => now()->toISOString(),
            ],
        ]);
    }

    public function stats(string $resource): JsonResponse
    {
        $this->guardResource($resource);

        $records = PlatformRecord::query()
            ->where('resource', $resource)
            ->get();

        $rows = $records->map(fn (PlatformRecord $record): array => $this->flatten($record));
        $statusCounts = $records->countBy('status');
        $sum = fn (string ...$keys): float => (float) $rows->sum(
            fn (array $row): float => collect($keys)
                ->map(fn (string $key): float => (float) ($row[$key] ?? 0))
                ->first(fn (float $value): bool => $value !== 0.0) ?? 0.0,
        );
        $average = fn (string $key): float => round((float) $rows->avg($key), 2);
        $total = $records->count();

        return response()->json([
            'total' => $total,
            'total_rules' => $total,
            'active' => (int) ($statusCounts['active'] ?? 0),
            'active_rules' => (int) ($statusCounts['active'] ?? 0),
            'inactive' => (int) ($statusCounts['inactive'] ?? 0),
            'blocked' => (int) ($statusCounts['blocked'] ?? 0),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'pending_approval' => (int) (
                ($statusCounts['pending'] ?? 0)
                + ($statusCounts['needs_approval'] ?? 0)
            ),
            'paused' => (int) ($statusCounts['paused'] ?? 0),
            'draft' => (int) ($statusCounts['draft'] ?? 0),
            'scheduled' => (int) ($statusCounts['scheduled'] ?? 0),
            'sent' => (int) ($statusCounts['sent'] ?? 0),
            'failed' => (int) ($statusCounts['failed'] ?? 0),
            'suspended' => (int) ($statusCounts['suspended'] ?? 0),
            'online' => (int) $rows->where('is_online', true)->count(),
            'busy' => (int) ($statusCounts['busy'] ?? 0),
            'total_orders' => $sum('orders_count', 'total_orders'),
            'orders_count' => $sum('orders_count', 'total_orders'),
            'deliveries' => $sum('deliveries_count', 'completed_deliveries_count'),
            'total_spent' => $sum('total_spent'),
            'total_wallet_balance' => $sum('wallet_balance'),
            'wallet_balance' => $sum('wallet_balance'),
            'completed_tasks' => $sum('completed_tasks_count'),
            'running_tasks' => $sum('running_tasks_count'),
            'total_commission' => $sum('commission_amount', 'total_commission'),
            'platform_revenue' => $sum('platform_amount', 'platform_revenue'),
            'payout_amount' => $sum('beneficiary_amount', 'payout_amount'),
            'average_rate' => $average('percentage'),
            'average_rating' => $average('average_rating'),
            'success_rate' => $average('success_rate'),
            'approval_rate' => $average('approval_rate'),
            'average_execution_seconds' => $average('average_execution_seconds'),
            'delivery_rate' => $total > 0
                ? round(((int) ($statusCounts['sent'] ?? 0) / $total) * 100, 2)
                : 0,
            'pv' => $sum('pv'),
            'ev' => $sum('ev'),
            'ac' => $sum('ac'),
            'cv' => $sum('cv'),
            'sv' => $sum('sv'),
            'cpi' => $sum('ac') !== 0.0 ? round($sum('ev') / $sum('ac'), 2) : 0,
            'spi' => $sum('pv') !== 0.0 ? round($sum('ev') / $sum('pv'), 2) : 0,
        ]);
    }

    public function settings(): JsonResponse
    {
        $record = PlatformRecord::query()
            ->where('resource', 'settings')
            ->where('external_key', 'global')
            ->first();

        return response()->json([
            'data' => $record?->payload ?? [],
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $settings = $request->input('settings', $request->all());
        abort_unless(is_array($settings), 422, 'صيغة الإعدادات غير صحيحة.');

        $record = PlatformRecord::query()->updateOrCreate(
            [
                'resource' => 'settings',
                'external_key' => 'global',
            ],
            [
                'status' => 'active',
                'payload' => $settings,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ],
        );

        return response()->json([
            'message' => 'تم حفظ الإعدادات بنجاح في قاعدة البيانات.',
            'data' => $record->payload,
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $platformCount = fn (string $resource): int => PlatformRecord::query()
            ->where('resource', $resource)
            ->count();
        $platformStatusCount = fn (string $resource, array $statuses): int => PlatformRecord::query()
            ->where('resource', $resource)
            ->whereIn('status', $statuses)
            ->count();
        $platformSum = function (string $resource, string $field): float {
            return (float) PlatformRecord::query()
                ->where('resource', $resource)
                ->get()
                ->sum(fn (PlatformRecord $record): float => (float) data_get($record->payload, $field, 0));
        };

        $completedStatuses = ['completed', 'delivered'];
        $cancelledStatuses = ['cancelled', 'rejected'];
        $runningStatuses = [
            'pending',
            'accepted',
            'preparing',
            'ready',
            'assigned',
            'picked_up',
            'delivering',
        ];

        $ordersTotal = Order::query()->count() + $platformCount('orders');
        $ordersCompleted = Order::query()
            ->whereIn('status', $completedStatuses)
            ->count()
            + $platformStatusCount('orders', $completedStatuses);
        $ordersCancelled = Order::query()
            ->whereIn('status', $cancelledStatuses)
            ->count()
            + $platformStatusCount('orders', $cancelledStatuses);
        $audits = PlatformAuditLog::query()
            ->latest()
            ->limit(8)
            ->get();
        $deliveredOrders = Order::query()
            ->whereNotNull('picked_up_at')
            ->whereNotNull('delivered_at')
            ->latest()
            ->limit(500)
            ->get(['picked_up_at', 'delivered_at']);
        $preparedOrders = Order::query()
            ->whereNotNull('accepted_at')
            ->whereNotNull('ready_at')
            ->latest()
            ->limit(500)
            ->get(['accepted_at', 'ready_at']);
        $averageDelivery = round((float) $deliveredOrders->avg(
            fn (Order $order): float => (float) $order->picked_up_at->diffInMinutes($order->delivered_at),
        ), 1);
        $averagePreparation = round((float) $preparedOrders->avg(
            fn (Order $order): float => (float) $order->accepted_at->diffInMinutes($order->ready_at),
        ), 1);
        $recentCoreOrders = Order::query()
            ->with(['customer', 'store', 'driver'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'number' => $order->number,
                'customer_name' => $order->customer?->name,
                'store_name' => $order->store?->name_ar
                    ?? $order->store?->name_en,
                'driver_name' => $order->driver?->name,
                'status' => $order->status,
                'total' => (float) $order->total,
                'created_at' => $order->created_at?->toISOString(),
            ]);
        $recentPlatformOrders = PlatformRecord::query()
            ->where('resource', 'orders')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (PlatformRecord $record): array => $this->flatten($record));
        $recentOrders = $recentCoreOrders
            ->merge($recentPlatformOrders)
            ->sortByDesc('created_at')
            ->take(8)
            ->values();
        $trends = collect(range(6, 0))
            ->map(function (int $daysAgo): array {
                $date = today()->subDays($daysAgo);
                $platformOrders = PlatformRecord::query()
                    ->where('resource', 'orders')
                    ->whereDate('created_at', $date)
                    ->get();

                return [
                    'label' => $date->translatedFormat('D'),
                    'orders' => Order::query()
                        ->whereDate('created_at', $date)
                        ->count()
                        + $platformOrders->count(),
                    'revenue' => (float) Order::query()
                        ->whereDate('created_at', $date)
                        ->sum('total')
                        + (float) $platformOrders->sum(
                            fn (PlatformRecord $record): float =>
                                (float) data_get($record->payload, 'total', 0),
                        ),
                ];
            });

        return response()->json([
            'kpis' => [
                'orders_total' => $ordersTotal,
                'orders_today' => Order::query()
                    ->whereDate('created_at', today())
                    ->count()
                    + PlatformRecord::query()
                    ->where('resource', 'orders')
                    ->whereDate('created_at', today())
                    ->count(),
                'orders_running' => Order::query()
                    ->whereIn('status', $runningStatuses)
                    ->count()
                    + $platformStatusCount('orders', $runningStatuses),
                'orders_completed' => $ordersCompleted,
                'revenue_total' => (float) Order::query()->sum('total')
                    + $platformSum('orders', 'total'),
                'commissions_total' => (float) OrderCommission::query()
                    ->sum('commission_amount')
                    + $platformSum('commissions', 'commission_amount'),
                'customers_total' => Customer::query()->count()
                    + $platformCount('customers'),
                'families_total' => ProductiveFamily::query()->count(),
                'stores_total' => Store::query()->count()
                    + $platformCount('stores'),
                'products_total' => Product::query()->count()
                    + $platformCount('products'),
                'drivers_total' => Driver::query()->count()
                    + $platformCount('drivers'),
                'drivers_online' => Driver::query()
                    ->where('is_online', true)
                    ->count()
                    + $platformStatusCount('drivers', ['active', 'busy']),
                'digital_employees_total' => DigitalEmployee::query()->count()
                    + $platformCount('digital-employees'),
                'wallets_balance' => (float) Wallet::query()
                    ->sum('available_balance')
                    + $platformSum('wallets', 'available_balance'),
                'completion_rate' => $ordersTotal > 0
                    ? round(($ordersCompleted / $ordersTotal) * 100, 2)
                    : 0,
                'cancellation_rate' => $ordersTotal > 0
                    ? round(($ordersCancelled / $ordersTotal) * 100, 2)
                    : 0,
                'average_delivery_minutes' => $averageDelivery,
                'average_preparation_minutes' => $averagePreparation,
                'customer_satisfaction' => round(
                    (float) Driver::query()->whereNotNull('rating')->avg('rating'),
                    2,
                ),
            ],
            'trends' => $trends,
            'recent_orders' => $recentOrders,
            'recent_activities' => $audits
                ->map(fn (PlatformAuditLog $log): array => [
                    'id' => $log->id,
                    'title' => $log->action,
                    'description' => $log->resource,
                    'type' => 'info',
                    'created_at' => $log->created_at?->toISOString(),
                ])
                ->values(),
            'alerts' => [],
            'ai_summary' => 'جميع الوحدات الأساسية متصلة بقاعدة البيانات، ولا توجد تنبيهات حرجة مسجلة حاليًا.',
            'system_health' => [
                'api' => 'healthy',
                'database' => 'healthy',
                'queue' => 'healthy',
                'storage' => 'healthy',
                'ai_services' => 'healthy',
            ],
        ]);
    }

    public function export(Request $request, string $resource): StreamedResponse
    {
        $this->guardResource($resource);

        $rows = PlatformRecord::query()
            ->where('resource', $resource)
            ->latest()
            ->get()
            ->map(fn (PlatformRecord $record): array => $this->flatten($record));

        $headers = $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values()
            ->all();

        if ($headers === []) {
            $headers = ['id', 'status', 'created_at'];
        }

        $filename = sprintf(
            '%s-%s.csv',
            Str::slug($resource) ?: 'zad-export',
            now()->format('Y-m-d'),
        );

        return response()->streamDownload(
            function () use ($rows, $headers): void {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $headers);

                foreach ($rows as $row) {
                    fputcsv(
                        $stream,
                        collect($headers)
                            ->map(function (string $header) use ($row): string {
                                $value = $row[$header] ?? '';

                                return is_scalar($value) || $value === null
                                    ? (string) $value
                                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            })
                            ->all(),
                    );
                }

                fclose($stream);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function dashboardExport(Request $request): StreamedResponse
    {
        return $this->export($request, 'orders');
    }

    public function upload(Request $request, string $resource): JsonResponse
    {
        $this->guardResource($resource);

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv',
                'max:10240',
            ],
        ]);

        $path = $request
            ->file('file')
            ->store("admin/{$resource}", 'public');
        $url = Storage::disk('public')->url($path);

        return response()->json([
            'message' => 'تم رفع الملف بنجاح.',
            'path' => $path,
            'url' => $url,
            'data' => [
                'path' => $path,
                'url' => $url,
            ],
        ], 201);
    }

    private function guardResource(string $resource): void
    {
        abort_unless(
            in_array($resource, self::RESOURCES, true),
            404,
            'المورد المطلوب غير معروف.',
        );
    }

    private function findRecord(string $resource, int $record): PlatformRecord
    {
        $this->guardResource($resource);

        return PlatformRecord::query()
            ->where('resource', $resource)
            ->findOrFail($record);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $term = str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->toString());
            $query->where('payload', 'like', "%{$term}%");
        }

        $ignored = [
            'page',
            'per_page',
            'search',
            'sort',
            'direction',
            'format',
        ];

        foreach ($request->query() as $key => $value) {
            if (
                in_array($key, $ignored, true)
                || $value === ''
                || $value === null
                || $value === 'all'
                || is_array($value)
            ) {
                continue;
            }

            $needle = json_encode((string) $value, JSON_UNESCAPED_UNICODE);
            $query->where('payload', 'like', '%'.$needle.'%');
        }
    }

    private function payloadFromRequest(Request $request): array
    {
        $input = $request->all();

        if (
            array_key_exists('payload', $input)
            && is_array($input['payload'])
            && count($input) <= 3
        ) {
            $input = [
                ...$input['payload'],
                ...Arr::only($input, ['status', 'external_key']),
            ];
        }

        return Arr::except($input, [
            'id',
            'resource',
            'created_at',
            'updated_at',
            'deleted_at',
            'created_by',
            'updated_by',
            'external_key',
        ]);
    }

    private function statusFromPayload(
        array &$payload,
        string $fallback = 'active',
    ): string {
        $status = trim((string) ($payload['status'] ?? $fallback)) ?: $fallback;
        $payload['status'] = $status;

        return $status;
    }

    private function externalKey(array $payload, ?string $fallback = null): ?string
    {
        foreach (['external_key', 'code', 'reference', 'slug', 'email'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return $fallback;
    }

    private function flatten(PlatformRecord $record): array
    {
        return [
            ...($record->payload ?? []),
            'id' => $record->id,
            'status' => $record->status,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function statusForAction(string $action, string $fallback): string
    {
        return match ($action) {
            'activate', 'approve', 'implement', 'renew', 'restart', 'start' => 'active',
            'archive' => 'archived',
            'block' => 'blocked',
            'cancel' => 'cancelled',
            'close' => 'closed',
            'deactivate' => 'inactive',
            'pause' => 'paused',
            'publish', 'send' => 'sent',
            'reject' => 'rejected',
            'resolve' => 'resolved',
            'submit' => 'pending',
            'suspend' => 'suspended',
            default => $fallback,
        };
    }

    private function applyActionPayload(
        array $payload,
        string $action,
        Request $request,
    ): array {
        if ($action === 'status') {
            $payload['status'] = (string) $request->input('status', $payload['status'] ?? 'active');
        }

        if ($action === 'open-status') {
            $payload['is_open'] = $request->boolean('is_open');
        }

        if ($action === 'assign') {
            $payload = array_replace($payload, Arr::only($request->all(), [
                'assigned_to_id',
                'assigned_to_name',
                'assigned_agent_type',
            ]));
        }

        if ($action === 'reply') {
            $messages = is_array($payload['messages'] ?? null)
                ? $payload['messages']
                : [];
            $messages[] = [
                'id' => count($messages) + 1,
                'sender_name' => $request->user()?->name ?? 'ZAD Support',
                'sender_type' => 'agent',
                'message' => (string) $request->input('message', ''),
                'created_at' => now()->toISOString(),
            ];
            $payload['messages'] = $messages;
            $payload['messages_count'] = count($messages);
            $payload['first_response_at'] ??= now()->toISOString();
        }

        if ($action === 'send') {
            $payload['sent_at'] = now()->toISOString();
            $payload['delivered_count'] = (int) ($payload['recipients_count'] ?? 0);
        }

        if (in_array($action, ['credit', 'debit'], true)) {
            $amount = max((float) $request->input('amount', 0), 0);
            $currentBalance = (float) ($payload['available_balance'] ?? 0);

            abort_if(
                $action === 'debit' && $amount > $currentBalance,
                422,
                'الرصيد المتاح لا يكفي لتنفيذ عملية الخصم.',
            );

            $direction = $action === 'credit' ? 1 : -1;
            $payload['available_balance'] = round(
                $currentBalance + ($amount * $direction),
                2,
            );
            $transactions = is_array($payload['transactions'] ?? null)
                ? $payload['transactions']
                : [];
            array_unshift($transactions, [
                'id' => (string) Str::uuid(),
                'type' => $action,
                'amount' => $amount,
                'reason' => $request->input('reason'),
                'reference' => $request->input('reference'),
                'created_at' => now()->toISOString(),
            ]);
            $payload['transactions'] = array_slice($transactions, 0, 100);
        }

        if (in_array($action, ['freeze', 'unfreeze'], true)) {
            $payload['is_frozen'] = $action === 'freeze';
            $payload['frozen_at'] = $action === 'freeze'
                ? now()->toISOString()
                : null;
        }

        if (in_array($action, ['enable-2fa', 'disable-2fa'], true)) {
            $payload['two_factor_enabled'] = $action === 'enable-2fa';
        }

        if ($action === 'reset-password') {
            $payload['password_reset_requested_at'] = now()->toISOString();
        }

        if ($action === 'revoke-sessions') {
            $payload['sessions_revoked_at'] = now()->toISOString();
        }

        if ($action === 'refund') {
            $payload['refunded_amount'] = (float) $request->input(
                'amount',
                $payload['amount'] ?? $payload['gross_amount'] ?? 0,
            );
            $payload['refunded_at'] = now()->toISOString();
        }

        $payload['last_action'] = $action;
        $payload['last_action_at'] = Carbon::now()->toISOString();

        return $payload;
    }

    private function audit(
        Request $request,
        PlatformRecord $record,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        PlatformAuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'resource' => $record->resource,
            'record_id' => $record->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 1000),
        ]);
    }
}
