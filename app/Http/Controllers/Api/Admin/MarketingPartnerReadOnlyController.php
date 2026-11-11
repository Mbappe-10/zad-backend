<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaunchCreator;
use App\Models\PlatformRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingPartnerReadOnlyController extends Controller
{
    private function creator(Request $request): LaunchCreator
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('marketing_partner') && $user->hasPermission('coupons.view'), 403);
        return LaunchCreator::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function code(PlatformRecord $record): string
    {
        $p = (array) $record->payload;
        return strtoupper(trim((string) ($p['code'] ?? $record->external_key ?? '')));
    }

    private function windows(int $creatorId): array
    {
        $windows = [];
        $legacy = [];
        foreach (PlatformRecord::query()->where('resource', 'creator-coupon-links')->get() as $link) {
            $p = (array) $link->payload;
            $owner = (int) ($p['creator_id'] ?? 0);
            if (!$owner && !empty($p['assignment_id'])) {
                $id = (int) $p['assignment_id'];
                if (!array_key_exists($id, $legacy)) {
                    $legacy[$id] = (int) (DB::table('launch_campaign_creators')->where('id', $id)->value('creator_id') ?? 0);
                }
                $owner = $legacy[$id];
            }
            if ($owner !== $creatorId || empty($p['linked_at'])) continue;
            $code = strtoupper(trim((string) ($p['coupon_code'] ?? '')));
            if ($code === '') continue;
            try {
                $start = Carbon::parse($p['linked_at'])->utc()->toDateTimeString();
                $end = empty($p['unlinked_at']) ? null : Carbon::parse($p['unlinked_at'])->utc()->toDateTimeString();
            } catch (\Throwable $e) { continue; }
            if ($end !== null && $end <= $start) continue;
            $windows[$code][] = [$start, $end];
        }
        return $windows;
    }

    private function coupons(array $windows): \Illuminate\Support\Collection
    {
        return PlatformRecord::query()->where('resource', 'coupons-offers')->get()
            ->filter(fn ($r) => strtolower((string) (($r->payload ?? [])['kind'] ?? '')) === 'coupon'
                && isset($windows[$this->code($r)]) && $this->code($r) !== '');
    }

    public function index(Request $request): JsonResponse
    {
        $windows = $this->windows($this->creator($request)->id);
        $data = $this->coupons($windows)->map(function ($r) {
            $p = (array) $r->payload;
            // Whitelist only non-sensitive display fields.
            return ['id' => $r->id, 'code' => $this->code($r), 'nameAr' => (string) ($p['nameAr'] ?? ''),
                'nameEn' => (string) ($p['nameEn'] ?? ''), 'status' => $r->status,
                'kind' => 'coupon'];
        })->values();
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, int $coupon): JsonResponse
    {
        $creatorId = $this->creator($request)->id;
        $windows = $this->windows($creatorId);
        $r = $this->coupons($windows)->firstWhere('id', $coupon);
        abort_unless($r, 404);
        $code = $this->code($r);
        $usage = DB::table('order_coupon_usages as usage')->where('usage.coupon_code', $code)
            ->whereIn('usage.status', ['applied', 'consumed'])
            ->where(function ($q) use ($windows, $code) {
                foreach ($windows[$code] as [$start, $end]) {
                    $q->orWhere(function ($w) use ($start, $end) {
                        $w->where('usage.created_at', '>=', $start);
                        if ($end !== null) $w->where('usage.created_at', '<', $end);
                    });
                }
            });
        $paid = (clone $usage)->join('orders', 'orders.id', '=', 'usage.order_id')
            ->where('orders.payment_status', 'paid')->whereNotIn('orders.status', ['cancelled', 'rejected'])
            ->whereNull('orders.deleted_at');
        $paidIds = (clone $paid)->distinct()->pluck('orders.id');
        $sponsoredTotals = DB::table('sponsored_order_fundings as funding')
            ->join('sponsored_creator_coupons as sponsored_coupon', 'sponsored_coupon.id', '=', 'funding.coupon_id')
            ->join('orders as sponsored_order', 'sponsored_order.id', '=', 'funding.order_id')
            ->where('sponsored_coupon.creator_id', $creatorId)
            ->where('funding.funding_source', 'zad_platform')
            ->where('funding.status', 'committed')
            ->whereNotIn('sponsored_order.status', ['cancelled', 'rejected'])
            ->whereNull('sponsored_order.deleted_at')
            ->selectRaw('COUNT(DISTINCT funding.order_id) as order_count')
            ->selectRaw('COALESCE(SUM(funding.funded_total), 0) as funded_total')
            ->first();
        $refundCount = $paidIds->isEmpty() ? 0 : DB::table('refunds')->whereIn('order_id', $paidIds)
            ->whereIn('status', ['approved', 'completed'])->distinct()->count('order_id');
        $p = (array) $r->payload;
        return response()->json(['data' => [
            'coupon_code' => $code, 'coupon_name' => (string) ($p['nameAr'] ?? $p['nameEn'] ?? $code),
            'coupon_status' => $r->status, 'recorded_uses' => (clone $usage)->count(),
            'unique_registered_customers' => (clone $usage)->whereNotNull('usage.customer_id')->distinct()->count('usage.customer_id'),
            'paid_order_count' => $paidIds->count(),
            'sponsored_summary' => [
                'scope' => 'creator',
                'sponsored_order_count' => (int) ($sponsoredTotals->order_count ?? 0),
                'sponsored_funded_total' => round((float) ($sponsoredTotals->funded_total ?? 0), 2),
            ],
            'unique_registered_paid_customers' => (clone $paid)->whereNotNull('orders.customer_id')->distinct()->count('orders.customer_id'),
            'paid_order_gmv' => round((float) (clone $paid)->sum('orders.total'), 2),
            'discount_on_paid_orders' => round((float) (clone $paid)->sum('usage.discount_amount'), 2),
            'orders_with_approved_refund' => $refundCount,
            'first_usage_at' => (clone $usage)->min('usage.created_at'),
            'last_usage_at' => (clone $usage)->max('usage.created_at'),
            'note' => 'ط§ظ„ط·ظ„ط¨ط§طھ ط§ظ„ظ…ط¯ظپظˆط¹ط© ظ„ط§ طھط¹ظ†ظٹ ط§ظ„طھط³ظˆظٹط© ط§ظ„ط¨ظ†ظƒظٹط©. ط§ظ„ظ†طھط§ط¦ط¬ طھط®طµ ظپطھط±ط§طھ ط±ط¨ط· ط§ظ„ظƒظˆط¨ظˆظ† ط¨ظ‡ط°ط§ ط§ظ„ط´ط±ظٹظƒ ظپظ‚ط·.',
        ]])->header('Cache-Control', 'no-store');
    }
}
