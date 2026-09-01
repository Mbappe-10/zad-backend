<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Models\FinancialLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\OrderSettlement;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FinancialService;
use App\Services\OrderSettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinancialService $service,
        private readonly OrderSettlementService $settlementsService,
    ) {
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => [
            'wallets' => Wallet::count(),
            'available_balance' => (float) Wallet::sum('available_balance'),
            'pending_balance' => (float) Wallet::sum('pending_balance'),
            'payments_total' => (float) Payment::where('status', 'paid')->sum('gross_amount'),
            'provider_fees' => (float) Payment::where('status', 'paid')->sum('provider_fee'),
            'payouts_pending' => (float) Payout::where('status', 'pending')->sum('amount'),
            'refunds_total' => (float) Refund::where('status', 'completed')->sum('amount'),
        ]]);
    }

    public function providers(Request $request): JsonResponse
    {
        $query = PaymentProvider::query();
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->search.'%')->orWhere('code', 'like', '%'.$request->search.'%'));
        }

        return response()->json($query->latest()->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:50|unique:payment_providers,code', 'name' => 'required|string|max:150', 'fixed_fee' => 'nullable|numeric|min:0', 'percentage_fee' => 'nullable|numeric|min:0|max:100', 'currency' => 'nullable|string|size:3', 'is_active' => 'nullable|boolean', 'settings' => 'nullable|array']);

        return response()->json(['message' => 'تمت إضافة مزود الدفع.', 'data' => PaymentProvider::create($data)], 201);
    }

    public function updateProvider(Request $request, PaymentProvider $provider): JsonResponse
    {
        $data = $request->validate(['code' => 'sometimes|string|max:50|unique:payment_providers,code,'.$provider->id, 'name' => 'sometimes|string|max:150', 'fixed_fee' => 'nullable|numeric|min:0', 'percentage_fee' => 'nullable|numeric|min:0|max:100', 'currency' => 'nullable|string|size:3', 'is_active' => 'nullable|boolean', 'settings' => 'nullable|array']);
        $provider->update($data);

        return response()->json(['message' => 'تم تحديث مزود الدفع.', 'data' => $provider->fresh()]);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['order:id,number', 'provider:id,name']);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$request->search.'%')->orWhere('provider_reference', 'like', '%'.$request->search.'%'));
        }

        return response()->json($query->latest()->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data = $request->validate(['order_id' => 'nullable|exists:orders,id', 'provider_id' => 'nullable|exists:payment_providers,id', 'reference' => 'required|string|max:100|unique:payments,reference', 'provider_reference' => 'nullable|string|max:150', 'method' => 'nullable|string|max:80', 'currency' => 'nullable|string|size:3', 'gross_amount' => 'required|numeric|min:0.01', 'status' => 'required|in:pending,paid,failed,cancelled', 'metadata' => 'nullable|array']);
        $provider = isset($data['provider_id']) ? PaymentProvider::find($data['provider_id']) : null;
        $fee = round((float) ($provider?->fixed_fee ?? 0) + ((float) $data['gross_amount'] * (float) ($provider?->percentage_fee ?? 0) / 100), 2);
        $data['provider_fee'] = $fee;
        $data['net_amount'] = round((float) $data['gross_amount'] - $fee, 2);
        if ($data['status'] === 'paid') {
            $data['paid_at'] = now();
        } if ($data['status'] === 'failed') {
            $data['failed_at'] = now();
        }

        return response()->json(['message' => 'تم تسجيل الدفعة.', 'data' => Payment::create($data)], 201);
    }

    public function commissionRules(Request $request): JsonResponse
    {
        $query = CommissionRule::query();
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        return response()->json($query->orderBy('priority')->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function storeCommissionRule(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'beneficiary_type' => 'required|in:platform,store,driver', 'calculation_type' => 'required|in:percentage,fixed', 'value' => 'required|numeric|min:0', 'minimum_amount' => 'nullable|numeric|min:0', 'maximum_amount' => 'nullable|numeric|min:0', 'city_id' => 'nullable|exists:cities,id', 'store_id' => 'nullable|exists:stores,id', 'vehicle_type' => 'nullable|string|max:50', 'priority' => 'nullable|integer|min:1', 'is_active' => 'nullable|boolean', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at']);

        return response()->json(['message' => 'تم إنشاء قاعدة العمولة.', 'data' => CommissionRule::create($data)], 201);
    }

    public function updateCommissionRule(Request $request, CommissionRule $rule): JsonResponse
    {
        $data = $request->validate(['name' => 'sometimes|string|max:150', 'beneficiary_type' => 'sometimes|in:platform,store,driver', 'calculation_type' => 'sometimes|in:percentage,fixed', 'value' => 'sometimes|numeric|min:0', 'minimum_amount' => 'nullable|numeric|min:0', 'maximum_amount' => 'nullable|numeric|min:0', 'city_id' => 'nullable|exists:cities,id', 'store_id' => 'nullable|exists:stores,id', 'vehicle_type' => 'nullable|string|max:50', 'priority' => 'nullable|integer|min:1', 'is_active' => 'nullable|boolean', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date']);
        $rule->update($data);

        return response()->json(['message' => 'تم تحديث قاعدة العمولة.', 'data' => $rule->fresh()]);
    }

    public function wallets(Request $request): JsonResponse
    {
        $query = Wallet::query();
        if ($request->filled('is_frozen')) {
            $query->where('is_frozen', $request->boolean('is_frozen'));
        }

        return response()->json($query->latest()->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $query = WalletTransaction::query();
        if ($request->filled('wallet_id')) {
            $query->where('wallet_id', $request->wallet_id);
        } if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json($query->latest()->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function creditWallet(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'type' => 'required|string|max:40', 'description' => 'required|string|max:500']);

        return response()->json(['message' => 'تمت إضافة الرصيد.', 'data' => $this->service->credit($wallet, (float) $data['amount'], $data['type'], $data['description'], null, $request->user()?->id)], 201);
    }

    public function freezeWallet(Wallet $wallet): JsonResponse
    {
        $wallet->update(['is_frozen' => ! $wallet->is_frozen]);

        return response()->json(['message' => 'تم تحديث حالة المحفظة.', 'data' => $wallet->fresh()]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $q = Payout::query()->with('wallet');
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

return response()->json($q->latest()->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function requestPayout(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1', 'fee' => 'nullable|numeric|min:0', 'bank_name' => 'nullable|string|max:150', 'iban' => 'nullable|string|max:50', 'account_name' => 'nullable|string|max:150', 'notes' => 'nullable|string']);

        return response()->json(['message' => 'تم إنشاء طلب الصرف.', 'data' => $this->service->requestPayout($wallet, $data, $request->user()?->id)], 201);
    }

    public function decidePayout(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject']);

        return response()->json(['message' => 'تم تنفيذ القرار.', 'data' => $this->service->decidePayout($payout, $data['decision'], $request->user()?->id)]);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:1000']);

        return response()->json(['message' => 'تم تنفيذ الاسترداد.', 'data' => $this->service->refund($payment, $data, $request->user()?->id)], 201);
    }

    public function ledger(Request $request): JsonResponse
    {
        $q = FinancialLedgerEntry::query();
        if ($request->filled('account_code')) {
            $q->where('account_code', $request->account_code);
        }

return response()->json($q->latest('entry_date')->latest('id')->paginate(min((int) $request->input('per_page',50),200)));
    }

    public function settlements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,released,held,reversed'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'productive_family_id' => ['nullable', 'integer', 'exists:productive_families,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $this->settlementQuery($data);
        $result = $query
            ->with([
                'order:id,number,status,delivered_at',
                'store:id,name_ar,name_en',
                'productiveFamily:id,owner_name,phone',
                'driver:id,name,phone',
            ])
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        $summaryQuery = $this->settlementQuery($data);

        return response()->json([
            ...$result->toArray(),
            'summary' => [
                'count' => (clone $summaryQuery)->count(),
                'pending_count' => (clone $summaryQuery)
                    ->where('status', OrderSettlement::STATUS_PENDING)
                    ->count(),
                'held_count' => (clone $summaryQuery)
                    ->where('status', OrderSettlement::STATUS_HELD)
                    ->count(),
                'released_count' => (clone $summaryQuery)
                    ->where('status', OrderSettlement::STATUS_RELEASED)
                    ->count(),
                'family_net' => (float) (clone $summaryQuery)->sum('family_net'),
                'driver_net' => (float) (clone $summaryQuery)->sum('driver_net'),
                'platform_total' => (float) (clone $summaryQuery)->sum('platform_total'),
            ],
        ]);
    }

    public function settlementSettings(): JsonResponse
    {
        return response()->json([
            'data' => [
                'release_delay_hours' => (int) $this->settlementSetting(
                    'settlements.release_delay_hours',
                    24,
                ),
                'family_commission_percentage' => (float) $this->settlementSetting(
                    'settlements.family_commission_percentage',
                    0,
                ),
                'driver_commission_percentage' => (float) $this->settlementSetting(
                    'settlements.driver_commission_percentage',
                    0,
                ),
                'automatic_release_enabled' => (bool) $this->settlementSetting(
                    'settlements.automatic_release_enabled',
                    true,
                ),
            ],
        ]);
    }

    public function updateSettlementSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'release_delay_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'family_commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'driver_commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'automatic_release_enabled' => ['required', 'boolean'],
        ]);

        $mapping = [
            'release_delay_hours' => ['settlements.release_delay_hours', 'integer'],
            'family_commission_percentage' => ['settlements.family_commission_percentage', 'decimal'],
            'driver_commission_percentage' => ['settlements.driver_commission_percentage', 'decimal'],
            'automatic_release_enabled' => ['settlements.automatic_release_enabled', 'boolean'],
        ];

        foreach ($mapping as $field => [$key, $type]) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($data[$field]),
                    'type' => $type,
                    'group' => 'settlements',
                    'is_public' => false,
                    'updated_by' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        return $this->settlementSettings();
    }

    public function releaseSettlement(
        Request $request,
        OrderSettlement $settlement,
    ): JsonResponse {
        $settlement = $this->settlementsService->release(
            $settlement,
            $request->user()?->id,
            true,
        );

        return response()->json([
            'message' => 'تم تحرير مستحقات الطلب.',
            'data' => $settlement,
        ]);
    }

    public function holdSettlement(
        Request $request,
        OrderSettlement $settlement,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $settlement = $this->settlementsService->hold(
            $settlement,
            $data['reason'],
        );

        return response()->json([
            'message' => 'تم إيقاف التسوية للمراجعة.',
            'data' => $settlement,
        ]);
    }

    public function exportSettlements(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,released,held,reversed'],
            'driver_id' => ['nullable', 'integer'],
            'productive_family_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $rows = $this->settlementQuery($data)
            ->with(['order:id,number', 'productiveFamily:id,owner_name', 'driver:id,name'])
            ->latest('id')
            ->limit(20000)
            ->get();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'رقم الطلب',
                'الأسرة المنتجة',
                'المندوب',
                'إجمالي الطلب',
                'إجمالي الأسرة',
                'عمولة الأسرة',
                'صافي الأسرة',
                'أجرة التوصيل',
                'عمولة المندوب',
                'صافي المندوب',
                'دخل المنصة',
                'الحالة',
                'موعد التحرير',
                'تاريخ التحرير',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->order?->number,
                    $row->productiveFamily?->owner_name,
                    $row->driver?->name,
                    $row->order_total,
                    $row->family_gross,
                    $row->family_commission,
                    $row->family_net,
                    $row->driver_gross,
                    $row->driver_commission,
                    $row->driver_net,
                    $row->platform_total,
                    $row->status,
                    $row->release_due_at?->toDateTimeString(),
                    $row->released_at?->toDateTimeString(),
                ]);
            }

            fclose($handle);
        }, 'zad-order-settlements-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function settlementQuery(array $filters): Builder
    {
        return OrderSettlement::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['driver_id'] ?? null, fn (Builder $query, int $id) => $query->where('driver_id', $id))
            ->when($filters['productive_family_id'] ?? null, fn (Builder $query, int $id) => $query->where('productive_family_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->whereHas('order', fn (Builder $order) => $order->where('number', 'like', '%'.trim($search).'%'));
            });
    }

    private function settlementSetting(string $key, mixed $fallback): mixed
    {
        $raw = DB::table('app_settings')->where('key', $key)->value('value');

        if ($raw === null) {
            return $fallback;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_string($raw) && json_last_error() !== JSON_ERROR_NONE
            ? $raw
            : $decoded;
    }
}
