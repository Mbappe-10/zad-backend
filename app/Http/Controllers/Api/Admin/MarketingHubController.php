<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaunchCreator;
use App\Models\PlatformRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stand-alone marketing partner and coupon reporting endpoints.
 * Contract papers are NEVER uploaded. Coupon settings and payments are NEVER edited here.
 */
class MarketingHubController extends Controller
{
    private const LINKS = 'creator-coupon-links';

    public function partners(): JsonResponse
    {
        return response()->json(['data' => LaunchCreator::query()
            ->orderBy('name')->get(['id', 'name', 'code', 'handle', 'phone', 'email', 'status', 'created_at'])]);
    }

    public function couponOptions(): JsonResponse
    {
        $rows = PlatformRecord::query()->where('resource', 'coupons-offers')->orderBy('id')->get()
            ->filter(static fn (PlatformRecord $record): bool =>
                strtolower((string) (($record->payload ?? [])['kind'] ?? '')) === 'coupon')
            ->map(static function (PlatformRecord $record): array {
                $p = (array) $record->payload;
                $code = strtoupper(trim((string) ($p['code'] ?? $record->external_key ?? '')));
                return ['id' => $record->id, 'code' => $code,
                    'name' => (string) ($p['nameAr'] ?? $p['nameEn'] ?? $code),
                    'status' => $record->status,
                    'managed' => ($p['creatorContractManaged'] ?? false) === true];
            })->filter(static fn (array $coupon): bool => $coupon['code'] !== '')
            ->unique('code')->values();

        return response()->json(['data' => $rows]);
    }

    private function rawLinks(): \Illuminate\Support\Collection
    {
        return PlatformRecord::query()->where('resource', self::LINKS)->orderByDesc('id')->get();
    }

    private function actualCreatorId(array $payload): int
    {
        if (! empty($payload['creator_id'])) {
            return (int) $payload['creator_id'];
        }
        if (empty($payload['assignment_id'])) {
            return 0;
        }
        return (int) (DB::table('launch_campaign_creators')
            ->where('id', (int) $payload['assignment_id'])->value('creator_id') ?? 0);
    }

    public function links(Request $request): JsonResponse
    {
        $data = $request->validate(['creator_id' => ['required', 'integer', 'exists:launch_creators,id']]);
        $creatorId = (int) $data['creator_id'];
        $links = $this->rawLinks()->filter(fn (PlatformRecord $record): bool =>
            $this->actualCreatorId((array) $record->payload) === $creatorId)->take(100);

        return response()->json(['data' => $links->map(function (PlatformRecord $record): array {
            $p = (array) $record->payload;
            $query = DB::table('order_coupon_usages as usage')
                ->where('usage.coupon_code', (string) ($p['coupon_code'] ?? ''))
                ->whereIn('usage.status', ['applied', 'consumed'])
                ->where('usage.created_at', '>=', $p['linked_at']);
            if (! empty($p['unlinked_at'])) {
                $query->where('usage.created_at', '<', $p['unlinked_at']);
            }
            $paid = (clone $query)->join('orders', 'orders.id', '=', 'usage.order_id')
                ->where('orders.payment_status', 'paid')
                ->whereNotIn('orders.status', ['cancelled', 'rejected'])
                ->whereNull('orders.deleted_at');
            return ['id' => $record->id, ...$p,
                'creator_id' => $this->actualCreatorId($p),
                'recorded_uses' => (clone $query)->count(),
                'paid_order_count' => (clone $paid)->distinct()->count('orders.id'),
                'discount_on_paid_orders' => round((float) (clone $paid)->sum('usage.discount_amount'), 2)];
        })->values()]);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'creator_id' => ['required', 'integer', 'exists:launch_creators,id'],
            'coupon_code' => ['required', 'string', 'max:80'],
            'linked_at' => ['required', 'date'],
            'paper_signed' => ['required', 'boolean'],
            'paper_signed_on' => ['nullable', 'required_if:paper_signed,true', 'date', 'before_or_equal:today'],
        ]);
        $code = strtoupper(trim((string) $data['coupon_code']));
        $linkedAt = Carbon::parse($data['linked_at'])->utc();
        if ($linkedAt->isFuture() || $linkedAt->lt(now()->subYears(10))) {
            throw ValidationException::withMessages(['linked_at' => ['تاريخ الربط غير صحيح.']]);
        }
        if ($data['paper_signed'] && empty($data['paper_signed_on'])) {
            throw ValidationException::withMessages(['paper_signed_on' => ['أدخل تاريخ توقيع العقد الخارجي.']]);
        }
        $coupon = PlatformRecord::query()->where('resource', 'coupons-offers')->get()
            ->first(static function (PlatformRecord $record) use ($code): bool {
                $p = (array) $record->payload;
                return strtolower((string) ($p['kind'] ?? '')) === 'coupon'
                    && strtoupper(trim((string) ($p['code'] ?? $record->external_key ?? ''))) === $code;
            });
        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => ['الكوبون غير موجود.']]);
        }
        $cp = (array) $coupon->payload;
        // Preserve historical contract-only codes: don't give them to another influencer.
        if (($cp['creatorContractManaged'] ?? false) === true) {
            $owner = (int) (DB::table('launch_campaign_creators')
                ->where('id', (int) ($cp['creatorAssignmentId'] ?? 0))->value('creator_id') ?? 0);
            if ($owner !== (int) $data['creator_id']) {
                throw ValidationException::withMessages(['coupon_code' => ['الكوبون حصري لعقد شريك آخر.']]);
            }
        }

        $record = DB::transaction(function () use ($data, $coupon, $code, $linkedAt, $request): PlatformRecord {
            PlatformRecord::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            foreach ($this->rawLinks() as $old) {
                $p = (array) $old->payload;
                if (strtoupper((string) ($p['coupon_code'] ?? '')) !== $code) {
                    continue;
                }
                // Protect both active and historical windows from overlapping attribution.
                $oldStart = Carbon::parse($p['linked_at']);
                $oldEnd = empty($p['unlinked_at']) ? null : Carbon::parse($p['unlinked_at']);
                if ($oldEnd === null || $linkedAt->lt($oldEnd)) {
                    throw ValidationException::withMessages(['coupon_code' => [
                        'توجد فترة ربط سابقة أو نشطة متداخلة. أغلق الربط الحالي أو اختر تاريخًا لاحقًا.',
                    ]]);
                }
            }
            return PlatformRecord::query()->create([
                'resource' => self::LINKS,
                'external_key' => 'CCL-'.$data['creator_id'].'-'.Str::lower(Str::random(12)),
                'status' => 'active',
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'payload' => [
                    'creator_id' => (int) $data['creator_id'], 'assignment_id' => null,
                    'coupon_code' => $code, 'linked_at' => $linkedAt->toDateTimeString(),
                    'unlinked_at' => null, 'paper_signed' => (bool) $data['paper_signed'],
                    'paper_signed_on' => $data['paper_signed'] ? $data['paper_signed_on'] : null,
                    'paper_signed_at' => $data['paper_signed'] ? now()->toDateTimeString() : null,
                ],
            ]);
        });
        return response()->json(['message' => 'تم ربط الكوبون بالشريك دون تغيير الكوبون.',
            'data' => ['id' => $record->id, ...$record->payload]], 201);
    }

    public function updateSignature(Request $request, int $link): JsonResponse
    {
        $data = $request->validate([
            'paper_signed' => ['required', 'boolean'],
            'paper_signed_on' => ['nullable', 'required_if:paper_signed,true', 'date', 'before_or_equal:today'],
        ]);
        if ($data['paper_signed'] && empty($data['paper_signed_on'])) {
            throw ValidationException::withMessages(['paper_signed_on' => ['أدخل تاريخ توقيع العقد.']]);
        }
        $record = PlatformRecord::query()->where('resource', self::LINKS)->findOrFail($link);
        $p = (array) $record->payload;
        $p['paper_signed'] = (bool) $data['paper_signed'];
        $p['paper_signed_on'] = $data['paper_signed'] ? $data['paper_signed_on'] : null;
        $p['paper_signed_at'] = $data['paper_signed'] ? now()->toDateTimeString() : null;
        $record->update(['payload' => $p, 'updated_by' => $request->user()?->id]);
        return response()->json(['message' => 'تم حفظ حالة العقد وتاريخ توقيعه.']);
    }

    public function closeLink(Request $request, int $link): JsonResponse
    {
        $record = PlatformRecord::query()->where('resource', self::LINKS)->findOrFail($link);
        $p = (array) $record->payload;
        if (empty($p['unlinked_at'])) {
            $p['unlinked_at'] = now()->toDateTimeString();
            $record->update(['status' => 'inactive', 'payload' => $p, 'updated_by' => $request->user()?->id]);
        }
        return response()->json(['message' => 'تم إنهاء ارتباط الكوبون دون تعطيله.']);
    }

    public function couponReport(Request $request, int $coupon): JsonResponse
    {
        $record = PlatformRecord::query()->where('resource', 'coupons-offers')->findOrFail($coupon);
        $p = (array) $record->payload;
        if (strtolower((string) ($p['kind'] ?? '')) !== 'coupon') {
            abort(404);
        }
        $code = strtoupper(trim((string) ($p['code'] ?? $record->external_key ?? '')));
        $usage = DB::table('order_coupon_usages as usage')->where('usage.coupon_code', $code)
            ->whereIn('usage.status', ['applied', 'consumed']);
        $paid = (clone $usage)->join('orders', 'orders.id', '=', 'usage.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.status', ['cancelled', 'rejected']);
        $paidIds = (clone $paid)->pluck('orders.id')->unique()->values();
        $refundCount = $paidIds->isEmpty() ? 0 : DB::table('refunds')
            ->whereIn('order_id', $paidIds)
            ->whereIn('status', ['approved', 'completed'])
            ->distinct()->count('order_id');
        $platformCommission = $paidIds->isEmpty() ? 0 : DB::table('order_commissions')
            ->whereIn('order_id', $paidIds)
            ->whereIn('beneficiary_type', ['platform', 'zad', 'zad_sync', 'platform_owner'])
            ->whereNotIn('status', ['cancelled', 'reversed', 'rejected'])
            ->sum('commission_amount');
        $links = $this->rawLinks()->filter(static fn (PlatformRecord $link): bool =>
            strtoupper((string) (($link->payload ?? [])['coupon_code'] ?? '')) === $code)
            ->map(fn (PlatformRecord $link): array => [
                'creator_id' => $this->actualCreatorId((array) $link->payload),
                'linked_at' => ($link->payload ?? [])['linked_at'] ?? null,
                'unlinked_at' => ($link->payload ?? [])['unlinked_at'] ?? null,
            ])->values();
        return response()->json(['data' => [
            'coupon_code' => $code,
            'coupon_name' => (string) ($p['nameAr'] ?? $p['nameEn'] ?? $code),
            'coupon_status' => $record->status,
            'recorded_uses' => (clone $usage)->count(),
            'unique_registered_customers' => (clone $usage)->whereNotNull('usage.customer_id')
                ->distinct()->count('usage.customer_id'),
            'paid_order_count' => $paidIds->count(),
            'unique_registered_paid_customers' => (clone $paid)->whereNotNull('orders.customer_id')
                ->distinct()->count('orders.customer_id'),
            'platform_recorded_commissions' => round((float) $platformCommission, 2),
            'paid_order_gmv' => round((float) (clone $paid)->sum('orders.total'), 2),
            'discount_on_paid_orders' => round((float) (clone $paid)->sum('usage.discount_amount'), 2),
            'orders_with_approved_refund' => $refundCount,
            'first_usage_at' => (clone $usage)->min('usage.created_at'),
            'last_usage_at' => (clone $usage)->max('usage.created_at'),
            'links' => $links,
            'note' => 'Paid means order payment_status=paid, not a bank settlement guarantee. Testing and refunds may be included. GMV is not ZAD net revenue.',
        ]]);
    }
}
