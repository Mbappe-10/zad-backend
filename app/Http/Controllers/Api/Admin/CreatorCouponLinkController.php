<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dated attribution for existing coupons and an acknowledgement of a PAPER contract.
 * Stored in platform_records to reuse the existing DB: no new migration or file uploads.
 * This service NEVER edits coupon status, price, checkout or financial commissions.
 */
class CreatorCouponLinkController extends Controller
{
    private const RESOURCE = 'creator-coupon-links';

    public function coupons(): JsonResponse
    {
        $coupons = PlatformRecord::query()->where('resource', 'coupons-offers')
            ->orderByDesc('created_at')->get()
            ->filter(static fn (PlatformRecord $record): bool =>
                strtolower(trim((string) (($record->payload ?? [])['kind'] ?? ''))) === 'coupon'
            )
            ->map(static function (PlatformRecord $record): array {
                $payload = (array) ($record->payload ?? []);
                $code = strtoupper(trim((string) ($payload['code'] ?? $record->external_key ?? '')));
                return [
                    'id' => $record->id,
                    'code' => $code,
                    'name' => (string) ($payload['nameAr'] ?? $payload['nameEn'] ?? $code),
                    'status' => (string) $record->status,
                    'creator_contract_managed' => ($payload['creatorContractManaged'] ?? false) === true,
                    'creator_assignment_id' => (int) ($payload['creatorAssignmentId'] ?? 0),
                ];
            })->filter(static fn (array $row): bool => $row['code'] !== '')
            ->unique('code')->values();
        return response()->json(['data' => $coupons]);
    }

    private function links(): \Illuminate\Support\Collection
    {
        return PlatformRecord::query()->where('resource', self::RESOURCE)
            ->orderByDesc('created_at')->get();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:launch_campaign_creators,id'],
        ]);
        $links = $this->links()->filter(static fn (PlatformRecord $record): bool =>
            (int) (($record->payload ?? [])['assignment_id'] ?? 0) === (int) $data['assignment_id']
        )->take(100);

        return response()->json(['data' => $links->map(function (PlatformRecord $record): array {
            $row = (array) $record->payload;
            $query = DB::table('order_coupon_usages as usage')
                ->where('usage.coupon_code', $row['coupon_code'])
                ->whereIn('usage.status', ['applied', 'consumed'])
                ->where('usage.created_at', '>=', $row['linked_at']);
            if (! empty($row['unlinked_at'])) {
                $query->where('usage.created_at', '<', $row['unlinked_at']);
            }
            $paid = (clone $query)->join('orders', 'orders.id', '=', 'usage.order_id')
                ->where('orders.payment_status', 'paid')
                ->whereNotIn('orders.status', ['cancelled', 'rejected'])
                ->whereNull('orders.deleted_at');
            return [
                'id' => $record->id,
                ...$row,
                'recorded_uses' => (clone $query)->count(),
                'paid_order_count' => (clone $paid)->distinct()->count('orders.id'),
                'discount_on_paid_orders' => round((float) (clone $paid)->sum('usage.discount_amount'), 2),
            ];
        })->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:launch_campaign_creators,id'],
            'coupon_code' => ['required', 'string', 'max:80'],
            'linked_at' => ['required', 'date'],
            'paper_signed' => ['required', 'boolean'],
        ]);
        $code = strtoupper(trim($data['coupon_code']));
        $linkedAt = Carbon::parse($data['linked_at'])->utc();
        if ($linkedAt->isFuture() || $linkedAt->lt(now()->subYears(10))) {
            throw ValidationException::withMessages(['linked_at' => ['يرجى تحديد تاريخ ربط سابق أو حالي صحيح.']]);
        }

        $coupon = PlatformRecord::query()->where('resource', 'coupons-offers')->get()
            ->first(static function (PlatformRecord $record) use ($code): bool {
                $payload = (array) ($record->payload ?? []);
                return strtolower(trim((string) ($payload['kind'] ?? ''))) === 'coupon'
                    && strtoupper(trim((string) ($payload['code'] ?? $record->external_key ?? ''))) === $code;
            });
        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => ['الكوبون غير موجود في صفحة الكوبونات والعروض.']]);
        }
        $couponData = (array) $coupon->payload;
        if (($couponData['creatorContractManaged'] ?? false) === true
            && (int) ($couponData['creatorAssignmentId'] ?? 0) !== (int) $data['assignment_id']) {
            throw ValidationException::withMessages(['coupon_code' => ['الكوبون تابع حصريًا لعقد مؤثر آخر.']]);
        }

        $record = DB::transaction(function () use ($request, $data, $code, $linkedAt, $coupon): PlatformRecord {
            // One lock per coupon makes a competing simultaneous link fail safely.
            PlatformRecord::query()->whereKey($coupon->id)->lockForUpdate()->first();
            $active = $this->links()->filter(static fn (PlatformRecord $item): bool =>
                $item->status === 'active' && empty(($item->payload ?? [])['unlinked_at'])
            );
            if ($active->contains(static fn (PlatformRecord $item): bool =>
                (($item->payload ?? [])['coupon_code'] ?? '') === $code
            )) {
                throw ValidationException::withMessages(['coupon_code' => ['الكوبون مرتبط حاليًا بمؤثر؛ يجب إنهاء الربط أولًا.']]);
            }
            $forAssignment = $active->filter(static fn (PlatformRecord $item): bool =>
                (int) (($item->payload ?? [])['assignment_id'] ?? 0) === (int) $data['assignment_id']
            );
            foreach ($forAssignment as $existing) {
                $payload = (array) $existing->payload;
                if ($linkedAt->lt(Carbon::parse($payload['linked_at']))) {
                    throw ValidationException::withMessages(['linked_at' => ['بداية الربط الجديد أقدم من بداية الربط الحالي.']]);
                }
                $payload['unlinked_at'] = $linkedAt->toDateTimeString();
                $existing->update(['status' => 'inactive', 'payload' => $payload]);
            }

            return PlatformRecord::query()->create([
                'resource' => self::RESOURCE,
                'external_key' => 'CCL-'.$data['assignment_id'].'-'.Str::lower(Str::random(12)),
                'status' => 'active',
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'payload' => [
                    'assignment_id' => (int) $data['assignment_id'],
                    'coupon_code' => $code,
                    'linked_at' => $linkedAt->toDateTimeString(),
                    'unlinked_at' => null,
                    'paper_signed' => (bool) $data['paper_signed'],
                    'paper_signed_at' => $data['paper_signed'] ? now()->toDateTimeString() : null,
                ],
            ]);
        });
        return response()->json([
            'message' => 'تم حفظ تاريخ ربط الكوبون دون تغيير صلاحيته.',
            'data' => ['id' => $record->id, ...$record->payload],
        ], 201);
    }

    public function signature(Request $request, int $link): JsonResponse
    {
        $data = $request->validate(['paper_signed' => ['required', 'boolean']]);
        $record = PlatformRecord::query()->where('resource', self::RESOURCE)->findOrFail($link);
        $payload = (array) $record->payload;
        $payload['paper_signed'] = (bool) $data['paper_signed'];
        $payload['paper_signed_at'] = $data['paper_signed']
            ? ($payload['paper_signed_at'] ?? now()->toDateTimeString()) : null;
        $record->update(['payload' => $payload, 'updated_by' => $request->user()?->id]);
        return response()->json(['message' => 'تم تسجيل حالة توقيع العقد الورقي.']);
    }

    public function close(Request $request, int $link): JsonResponse
    {
        $record = PlatformRecord::query()->where('resource', self::RESOURCE)->findOrFail($link);
        $payload = (array) $record->payload;
        if (empty($payload['unlinked_at'])) {
            $payload['unlinked_at'] = now()->toDateTimeString();
            $record->update(['status' => 'inactive', 'payload' => $payload, 'updated_by' => $request->user()?->id]);
        }
        return response()->json(['message' => 'انتهى الربط فقط؛ الكوبون الأصلي لم يتغير.']);
    }
}
