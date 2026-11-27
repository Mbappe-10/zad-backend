<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\CashierDevice;
use App\Models\CashierShift;
use App\Models\CashierStaff;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Services\App\CashierContext;
use App\Services\App\CashierStaffService;
use App\Services\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Staff, cashier devices, shifts and per-employee sales. Management is for the
 * family owner only; shifts work for whoever is using the cashier.
 */
class CashierStaffController extends Controller
{
    private const TZ = 'Asia/Riyadh';

    public function __construct(private readonly CashierStaffService $staff) {}

    // ------------------------------------------------------------ owner: staff

    public function index(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $this->staff->ownerRow($store);

        return response()->json(['data' => [
            'enabled' => $this->staff->enabled($store),
            'limit' => $this->staff->limit(),
            'count' => $this->staff->employeeCount($store),
            'staff' => $this->staff->members($store)->map(fn ($s) => $this->staff->staffPayload($s))->values(),
            'devices' => CashierDevice::query()->where('store_id', $store->id)->where('kind', 'cashier')->whereNull('revoked_at')->latest('id')->get()
                ->map(fn (CashierDevice $d) => $this->devicePayload($d))->values(),
            'permissions' => CashierStaffService::PERMISSIONS,
            'roles' => array_map(fn ($r) => $r, CashierStaffService::ROLES),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $this->requireStaff($store);
        $data = $this->validated($request, true);
        [$member, $invite] = $this->staff->create($store, $data);

        return response()->json([
            'message' => 'تمت إضافة '.$member->name.'.',
            'data' => $this->staff->staffPayload($member->fresh()) + ['invite' => ['code' => $invite->code, 'expires_at' => $invite->expires_at->toISOString()]],
        ], 201);
    }

    public function update(Request $request, CashierStaff $member): JsonResponse
    {
        $store = $this->ownerStore($request);
        abort_unless($member->store_id === $store->id && $member->removed_at === null, 404);
        if (! $member->isOwner()) {
            $this->requireStaff($store);
        }
        $member = $this->staff->update($member, $this->validated($request, false));

        return response()->json(['message' => 'تم الحفظ.', 'data' => $this->staff->staffPayload($member)]);
    }

    public function destroy(Request $request, CashierStaff $member): JsonResponse
    {
        $store = $this->ownerStore($request);
        abort_unless($member->store_id === $store->id, 404);
        $this->staff->remove($member);

        return response()->json(['message' => 'تم حذف '.$member->name.'. مبيعاتها السابقة تبقى باسمها.']);
    }

    public function invite(Request $request, CashierStaff $member): JsonResponse
    {
        $store = $this->ownerStore($request);
        abort_unless($member->store_id === $store->id && $member->removed_at === null && ! $member->isOwner(), 404);
        $this->requireStaff($store);
        $invite = $this->staff->newInvite($member);

        return response()->json(['data' => ['code' => $invite->code, 'expires_at' => $invite->expires_at->toISOString()]]);
    }

    // ------------------------------------------------------------ owner: devices

    public function linkDevice(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $this->requireStaff($store);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['cashier'])],
            'name' => ['required', 'string', 'max:60'],
        ]);
        $owner = $this->staff->ownerRow($store);
        abort_if($owner->pin_hash === null, 422, 'حددي رمزك أول، عشان تقدرين تفتحين الكاشير على هذا الجهاز.');
        [$device, $token] = $this->staff->linkDevice($store, $data['kind'], $data['name'], $request->user()->id);

        return response()->json([
            'message' => 'صار هذا الجهاز كاشير لمتجرك.',
            'data' => $this->devicePayload($device) + ['token' => $token],
        ], 201);
    }

    public function unlinkDevice(Request $request, CashierDevice $device): JsonResponse
    {
        $store = $this->ownerStore($request);
        abort_unless($device->store_id === $store->id, 404);
        $device->forceFill(['revoked_at' => now(), 'staff_id' => null, 'staff_token_hash' => null])->save();

        return response()->json(['message' => 'تم فصل الجهاز.']);
    }

    // ------------------------------------------------------------ owner: who sold what

    public function sales(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'staff_id' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['from'] ?? now(self::TZ)->toDateString(), self::TZ)->startOfDay();
        $to = Carbon::parse($data['to'] ?? $from->toDateString(), self::TZ)->endOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'اختاري فترة ٣ شهور أو أقل.');

        $base = SaleReceipt::query()->where('store_id', $store->id)->where('status', 'paid')
            ->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM)
            ->whereBetween('issued_at', [$from->copy()->utc(), $to->copy()->utc()]);

        $people = [];
        foreach ((clone $base)->get(['cashier_staff_id', 'staff_name', 'payment_method', 'total', 'discount']) ->groupBy(fn ($r) => (int) $r->cashier_staff_id) as $staffId => $group) {
            $people[] = [
                'staff_id' => $staffId ?: null,
                'name' => $group->first()->staff_name ?: 'المالكة',
                'sales_count' => $group->count(),
                'sales_total' => round((float) $group->sum('total'), 2),
                'cash' => round((float) $group->where('payment_method', 'cash')->sum('total'), 2),
                'network' => round((float) $group->where('payment_method', 'network')->sum('total'), 2),
                'other' => round((float) $group->whereNotIn('payment_method', ['cash', 'network'])->sum('total'), 2),
                'discounts' => round((float) $group->sum('discount'), 2),
            ];
        }
        usort($people, fn ($a, $b) => $b['sales_total'] <=> $a['sales_total']);

        $receipts = (clone $base)->when(isset($data['staff_id']), fn ($q) => $q->where('cashier_staff_id', $data['staff_id']))
            ->latest('issued_at')->limit(200)->get();
        $shifts = CashierShift::query()->where('store_id', $store->id)
            ->where('opened_at', '<=', $to->copy()->utc())
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $from->copy()->utc()))
            ->when(isset($data['staff_id']), fn ($q) => $q->where('staff_id', $data['staff_id']))
            ->latest('opened_at')->limit(100)->get();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'staff' => $people,
            'shifts' => $shifts->map(fn ($s) => $this->staff->shiftPayload($s))->values(),
            'receipts' => $receipts->map(fn (SaleReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'staff_name' => $r->staff_name ?: 'المالكة',
                'total' => (float) $r->total,
                'discount' => (float) $r->discount,
                'payment_method' => $r->payment_method,
                'method_label' => SaleReceiptService::METHOD_LABELS[$r->payment_method] ?? $r->payment_method,
                'issued_at' => $r->issued_at?->toISOString(),
                'url' => app(SaleReceiptService::class)->publicUrl($r),
            ])->values(),
        ]]);
    }

    // ------------------------------------------------------------ anyone: shifts

    public function currentShift(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request);
        $shift = $this->staff->openShift($ctx);

        return response()->json(['data' => [
            'shift' => $shift ? $this->staff->shiftPayload($shift) : null,
            'required' => ! $ctx->isOwner(),
            'me' => $this->me($ctx),
        ]]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request);
        $this->requireStaff($ctx->store);
        $data = $request->validate(['opening_cash' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        $shift = $this->staff->startShift($ctx, (float) $data['opening_cash']);

        return response()->json(['message' => 'بدأت الوردية. بالتوفيق!', 'data' => $this->staff->shiftPayload($shift)], 201);
    }

    public function closeShift(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request);
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $shift = $this->staff->closeShift($ctx, (float) $data['counted_cash'], $data['note'] ?? null);

        return response()->json(['message' => 'تم إقفال الوردية.', 'data' => $this->staff->shiftPayload($shift)]);
    }

    // ------------------------------------------------------------ helpers

    public static function me(CashierContext $ctx): array
    {
        return [
            'staff_id' => $ctx->staff?->id,
            'name' => $ctx->staff?->name,
            'role' => $ctx->staff?->role ?? 'owner',
            'owner' => $ctx->isOwner(),
            'permissions' => $ctx->isOwner() ? array_keys(CashierStaffService::PERMISSIONS) : ($ctx->staff?->permissions ?? []),
            'max_discount_percent' => $ctx->isOwner() ? 100 : (int) ($ctx->staff?->max_discount_percent ?? 0),
            'device' => $ctx->device ? ['id' => $ctx->device->id, 'name' => $ctx->device->name] : null,
        ];
    }

    private function devicePayload(CashierDevice $d): array
    {
        return [
            'id' => $d->id,
            'kind' => $d->kind,
            'name' => $d->name,
            'last_seen_at' => $d->last_seen_at?->toISOString(),
            'unlocked_by' => $d->staff_id ? CashierStaff::query()->whereKey($d->staff_id)->value('name') : null,
        ];
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:60'],
            'role' => [$req, Rule::in(array_keys(CashierStaffService::ROLES))],
            'pin' => [$creating ? 'required' : 'nullable', 'digits:4'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(array_keys(CashierStaffService::PERMISSIONS))],
            'max_discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function requireStaff(Store $store): void
    {
        abort_unless($this->staff->enabled($store), 403, 'الموظفين والورديات من مزايا كاشير زاد برو.');
    }

    private function ownerStore(Request $request): Store
    {
        abort_unless($this->staff->ready(), 503, 'الميزة قيد التجهيز.');
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner() && $ctx->device === null, 403, 'هذه للمالكة فقط.');

        return $ctx->store;
    }
}
