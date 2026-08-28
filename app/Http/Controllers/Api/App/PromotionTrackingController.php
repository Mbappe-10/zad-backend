<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PlatformRecord;
use App\Models\PromotionConversion;
use App\Models\PromotionInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PromotionTrackingController extends Controller
{
    public function impression(Request $request, int $promotion): JsonResponse
    {
        $record = $this->activePromotion($promotion);
        $data = $this->interactionData($request);

        $interaction = $this->recordInteraction(
            $record,
            'impression',
            $data,
            30,
        );

        return response()->json([
            'message' => 'تم تسجيل مشاهدة الإعلان.',
            'data' => ['recorded' => $interaction->wasRecentlyCreated],
        ]);
    }

    public function click(Request $request, int $promotion): JsonResponse
    {
        $record = $this->activePromotion($promotion);
        $data = $this->interactionData($request);
        $rawToken = Str::random(64);

        $interaction = $this->recordInteraction(
            $record,
            'click',
            $data,
            2,
        );

        $interaction->forceFill([
            'attribution_token_hash' => hash('sha256', $rawToken),
        ])->save();

        return response()->json([
            'message' => 'تم تسجيل النقرة.',
            'data' => [
                'recorded' => $interaction->wasRecentlyCreated,
                'attribution_token' => $rawToken,
                'expires_in_days' => 7,
            ],
        ]);
    }

    public function conversion(Request $request, int $promotion): JsonResponse
    {
        $record = $this->promotion($promotion);
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'attribution_token' => ['required', 'string', 'size:64'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
        ]);

        $order = Order::query()->findOrFail((int) $data['order_id']);
        $payload = is_array($record->payload) ? $record->payload : [];
        $productId = (int) (
            $payload['productId']
                ?? $payload['product_id']
                ?? $payload['targetId']
                ?? $payload['target_id']
                ?? 0
        );

        abort_if($order->status === Order::STATUS_CANCELLED, 422, 'الطلب ملغي.');
        abort_unless(
            $productId <= 0
                || $order->items()
                    ->where('product_id', $productId)
                    ->exists(),
            422,
            'الطلب لا يحتوي على المنتج المرتبط بالإعلان.',
        );
        $tokenHash = hash('sha256', (string) $data['attribution_token']);

        $conversion = DB::transaction(function () use (
            $data,
            $order,
            $record,
            $tokenHash,
        ): PromotionConversion {
            $interaction = PromotionInteraction::query()
                ->where('promotion_id', $record->id)
                ->where('event_type', 'click')
                ->where('attribution_token_hash', $tokenHash)
                ->where('occurred_at', '>=', now()->subDays(7))
                ->lockForUpdate()
                ->firstOrFail();

            $conversion = PromotionConversion::query()->firstOrCreate(
                ['order_id' => $order->id],
                [
                    'promotion_id' => $record->id,
                    'interaction_id' => $interaction->id,
                    'revenue' => (float) $order->total,
                    'coupon_code' => $data['coupon_code'] ?? null,
                    'city_id' => $order->city_id,
                    'converted_at' => now(),
                ],
            );

            if ($conversion->promotion_id === $record->id) {
                $interaction->forceFill([
                    'attributed_order_id' => $order->id,
                ])->save();
            }

            return $conversion;
        });

        return response()->json([
            'message' => 'تم ربط الطلب بالإعلان.',
            'data' => [
                'recorded' => $conversion->wasRecentlyCreated,
                'order_id' => $conversion->order_id,
            ],
        ]);
    }

    private function interactionData(Request $request): array
    {
        return $request->validate([
            'session_id' => ['required', 'string', 'min:12', 'max:120'],
            'city_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);
    }

    private function recordInteraction(
        PlatformRecord $promotion,
        string $type,
        array $data,
        int $bucketMinutes,
    ): PromotionInteraction {
        $sessionHash = hash('sha256', (string) $data['session_id']);
        $bucket = intdiv(now()->timestamp, $bucketMinutes * 60);
        $eventKey = hash(
            'sha256',
            implode('|', [$promotion->id, $type, $sessionHash, $bucket]),
        );

        return PromotionInteraction::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'promotion_id' => $promotion->id,
                'event_type' => $type,
                'session_hash' => $sessionHash,
                'city_id' => $data['city_id'] ?? null,
                'metadata' => [
                    'source' => $data['source'] ?? 'home',
                    'ip_hash' => hash('sha256', (string) request()->ip()),
                    'user_agent' => Str::limit(
                        (string) request()->userAgent(),
                        500,
                    ),
                ],
                'occurred_at' => now(),
            ],
        );
    }

    private function activePromotion(int $id): PlatformRecord
    {
        $record = $this->promotion($id);
        $payload = is_array($record->payload) ? $record->payload : [];
        $status = strtolower((string) ($payload['status'] ?? $record->status));
        $visible = filter_var(
            $payload['visible'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
        );

        abort_unless(in_array($status, ['active', 'scheduled'], true), 404);
        abort_unless($visible, 404);

        $startsAt = $this->dateValue($payload, [
            'startAt', 'startsAt', 'startDate', 'start_at', 'starts_at',
        ]);
        $endsAt = $this->dateValue($payload, [
            'endAt', 'endsAt', 'endDate', 'end_at', 'ends_at',
        ]);

        abort_if($startsAt !== null && $startsAt->isFuture(), 404);
        abort_if($endsAt !== null && $endsAt->isPast(), 404);

        return $record;
    }

    private function promotion(int $id): PlatformRecord
    {
        return PlatformRecord::query()
            ->whereIn('resource', ['ads', 'advertisements', 'coupons', 'offers'])
            ->findOrFail($id);
    }

    private function dateValue(array $payload, array $keys): ?Carbon
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            try {
                return Carbon::parse((string) $value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
