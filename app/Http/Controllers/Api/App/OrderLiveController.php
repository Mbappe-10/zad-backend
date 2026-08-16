<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\LiveBroadcastSetting;
use App\Models\Order;
use App\Models\OrderLiveSession;
use App\Models\Product;
use App\Models\Store;
use App\Services\DeliveryOperationsService;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderLiveController extends Controller
{
    private const TOKEN_TTL_SECONDS = 7200;

    public function __construct(
        private readonly DeliveryOperationsService $delivery,
    ) {
    }

    public function start(Request $request, Order $order): JsonResponse
    {
        $familyId = $this->ensureFamilyOwnsOrder($request, $order);

        if (! in_array($order->status, [
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
        ], true)) {
            throw ValidationException::withMessages([
                'order' => ['يبدأ البث بعد قبول الطلب وقبل إعلان جاهزيته.'],
            ]);
        }

        if ($order->status === Order::STATUS_ACCEPTED) {
            $order = $this->delivery->transition(
                $order,
                Order::STATUS_PREPARING,
                'بدأت الأسرة تجهيز الطلب والبث المباشر.',
                $request->user()?->id,
            );
        }

        $settings = LiveBroadcastSetting::current();
        $preparationMinutes = $this->preparationMinutes($order);
        $graceMinutes = (int) $settings->default_grace_minutes;

        $session = OrderLiveSession::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                OrderLiveSession::STATUS_LIVE,
                OrderLiveSession::STATUS_PAUSED,
            ])
            ->latest('id')
            ->first();

        if ($session === null) {
            $session = OrderLiveSession::create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'productive_family_id' => $familyId,
                'started_by_user_id' => $request->user()?->id,
                'room_name' => $this->newRoomName($order),
                'status' => OrderLiveSession::STATUS_WAITING,
                'quality_profile' => 'adaptive',
                'preparation_minutes' => $preparationMinutes,
                'grace_minutes' => $graceMinutes,
                'extended_minutes' => 0,
                'compliance' => [
                    'mask' => 'pending',
                    'gloves' => 'pending',
                    'workspace' => 'pending',
                    'method' => 'manual',
                ],
            ]);
        }

        $publisherIdentity = sprintf(
            'family-%d-session-%s',
            $familyId,
            $session->public_id,
        );

        $startedAt = $session->started_at ?? now();
        $scheduledEndAt = $session->scheduled_end_at
            ?? $startedAt->copy()->addMinutes(
                (int) $session->preparation_minutes
                + (int) $session->grace_minutes
                + (int) $session->extended_minutes,
            );

        $session->forceFill([
            'publisher_identity' => $publisherIdentity,
            'status' => OrderLiveSession::STATUS_LIVE,
            'started_at' => $startedAt,
            'scheduled_end_at' => $scheduledEndAt,
            'resumed_at' => $session->paused_at !== null ? now() : null,
            'paused_at' => null,
            'last_heartbeat_at' => now(),
            'ended_at' => null,
            'ended_reason' => null,
        ])->save();

        return response()->json([
            'message' => 'تم إنشاء غرفة البث الآمنة.',
            'data' => $this->connectionPayload(
                $session->fresh(),
                $publisherIdentity,
                canPublish: true,
                canSubscribe: false,
            ),
        ]);
    }

    public function pause(Request $request, Order $order): JsonResponse
    {
        $this->ensureFamilyOwnsOrder($request, $order);
        $session = $this->activeSession($order);

        $session->update([
            'status' => OrderLiveSession::STATUS_PAUSED,
            'paused_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم إيقاف البث مؤقتًا.',
            'data' => $this->sessionPayload($session->fresh()),
        ]);
    }

    public function resume(Request $request, Order $order): JsonResponse
    {
        $familyId = $this->ensureFamilyOwnsOrder($request, $order);
        $session = $this->activeSession($order);

        $publisherIdentity = sprintf(
            'family-%d-session-%s',
            $familyId,
            $session->public_id,
        );

        $session->update([
            'publisher_identity' => $publisherIdentity,
            'status' => OrderLiveSession::STATUS_LIVE,
            'paused_at' => null,
            'resumed_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم استئناف البث.',
            'data' => $this->connectionPayload(
                $session->fresh(),
                $publisherIdentity,
                canPublish: true,
                canSubscribe: false,
            ),
        ]);
    }

    public function finish(Request $request, Order $order): JsonResponse
    {
        $this->ensureFamilyOwnsOrder($request, $order);

        $data = $request->validate([
            'final_photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:12288',
            ],
        ]);

        $session = $this->activeSession($order);
        $path = $data['final_photo']->store(
            "order-live/{$order->id}",
            'public',
        );

        $session->update([
            'status' => OrderLiveSession::STATUS_ENDED,
            'final_photo_path' => $path,
            'final_photo_at' => now(),
            'last_heartbeat_at' => now(),
            'ended_at' => now(),
            'ended_reason' => 'order_ready',
        ]);

        $order->refresh();

        if ($order->status === Order::STATUS_ACCEPTED) {
            $order = $this->delivery->transition(
                $order,
                Order::STATUS_PREPARING,
                'بدأ تجهيز الطلب.',
                $request->user()?->id,
            );
        }

        if ($order->status === Order::STATUS_PREPARING) {
            $order = $this->delivery->transition(
                $order,
                Order::STATUS_READY,
                'انتهى البث وتم توثيق الصورة النهائية للطبق.',
                $request->user()?->id,
            );
        }

        $dispatchStatus = 'searching';
        $dispatchMessage = null;

        try {
            $this->delivery->autoAssign(
                $order,
                $request->user()?->id,
            );

            $order = $order->fresh();
            $dispatchStatus = 'assigned';
        } catch (ValidationException $exception) {
            $dispatchMessage = collect($exception->errors())
                ->flatten()
                ->first();
        }

        return response()->json([
            'message' => 'انتهى البث وحُفظت الصورة النهائية.',
            'dispatch_status' => $dispatchStatus,
            'dispatch_message' => $dispatchMessage,
            'data' => $this->sessionPayload($session->fresh()),
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'driver_id' => $order->driver_id,
            ],
        ]);
    }

    public function status(Request $request, Order $order): JsonResponse
    {
        $this->ensureCanViewOrder($request, $order);

        $session = OrderLiveSession::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $session === null
                ? [
                    'available' => false,
                    'status' => OrderLiveSession::STATUS_WAITING,
                ]
                : $this->sessionPayload($session),
        ]);
    }

    public function viewerToken(Request $request, Order $order): JsonResponse
    {
        $this->ensureCanViewOrder($request, $order);

        $session = OrderLiveSession::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                OrderLiveSession::STATUS_LIVE,
                OrderLiveSession::STATUS_PAUSED,
            ])
            ->latest('id')
            ->first();

        if ($session === null) {
            return response()->json([
                'data' => [
                    'available' => false,
                    'status' => OrderLiveSession::STATUS_WAITING,
                ],
            ]);
        }

        $guestSession = trim(
            (string) $request->header('X-Guest-Session', ''),
        );

        $viewerSeed = $request->user()?->id !== null
            ? 'user-'.$request->user()->id
            : 'guest-'.substr(hash('sha256', $guestSession), 0, 24);

        $identity = sprintf(
            '%s-order-%d-%s',
            $viewerSeed,
            $order->id,
            Str::lower((string) Str::random(8)),
        );

        return response()->json([
            'data' => $this->connectionPayload(
                $session,
                $identity,
                canPublish: false,
                canSubscribe: true,
            ),
        ]);
    }

    private function createToken(
        string $roomName,
        string $identity,
        bool $canPublish,
        bool $canSubscribe,
    ): string {
        $apiKey = trim((string) config('services.livekit.api_key'));
        $apiSecret = trim((string) config('services.livekit.api_secret'));

        if ($apiKey === '' || $apiSecret === '') {
            throw ValidationException::withMessages([
                'livekit' => ['إعدادات خدمة البث غير مكتملة.'],
            ]);
        }

        $issuedAt = time();

        return JWT::encode([
            'iss' => $apiKey,
            'sub' => $identity,
            'nbf' => $issuedAt - 5,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TOKEN_TTL_SECONDS,
            'video' => [
                'roomJoin' => true,
                'room' => $roomName,
                'canPublish' => $canPublish,
                'canSubscribe' => $canSubscribe,
                'canPublishData' => false,
                // الأسرة تنشر الكاميرا فقط: لا ميكروفون،
                // لا مشاركة شاشة، ولا صوت مشاركة شاشة.
                // المشاهد لا يملك صلاحية النشر أصلًا.
                'canPublishSources' => $canPublish
                    ? ['camera']
                    : [],
                'canUpdateOwnMetadata' => false,
            ],
            'metadata' => '',
        ], $apiSecret, 'HS256');
    }

    private function connectionPayload(
        OrderLiveSession $session,
        string $identity,
        bool $canPublish,
        bool $canSubscribe,
    ): array {
        return $this->sessionPayload($session) + [
            'server_url' => (string) config('services.livekit.url'),
            'participant_token' => $this->createToken(
                $session->room_name,
                $identity,
                $canPublish,
                $canSubscribe,
            ),
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'permissions' => [
                'can_publish' => $canPublish,
                'can_subscribe' => $canSubscribe,
                'can_publish_camera' => $canPublish,
                'can_publish_microphone' => false,
                'can_publish_screen_share' => false,
                'can_publish_data' => false,
            ],
        ];
    }

    private function sessionPayload(OrderLiveSession $session): array
    {
        $remainingSeconds = $session->scheduled_end_at === null
            ? 0
            : max(
                0,
                now()->diffInSeconds(
                    $session->scheduled_end_at,
                    false,
                ),
            );

        return [
            'id' => $session->public_id,
            'available' => $session->isWatchable(),
            'status' => $session->status,
            'quality_profile' => $session->quality_profile,
            'viewer_count' => $session->viewer_count,
            'peak_viewers' => $session->peak_viewers,
            'compliance' => $session->compliance ?? [],
            'started_at' => $session->started_at,
            'paused_at' => $session->paused_at,
            'ended_at' => $session->ended_at,
            'preparation_minutes' => (int) $session->preparation_minutes,
            'grace_minutes' => (int) $session->grace_minutes,
            'extended_minutes' => (int) $session->extended_minutes,
            'scheduled_end_at' => $session->scheduled_end_at,
            'remaining_seconds' => $remainingSeconds,
            'final_photo_url' => $session->final_photo_path === null
                ? null
                : Storage::disk('public')->url(
                    $session->final_photo_path,
                ),
        ];
    }

    private function activeSession(Order $order): OrderLiveSession
    {
        $session = OrderLiveSession::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                OrderLiveSession::STATUS_LIVE,
                OrderLiveSession::STATUS_PAUSED,
            ])
            ->latest('id')
            ->first();

        if ($session === null) {
            throw ValidationException::withMessages([
                'live' => ['لا توجد جلسة بث نشطة لهذا الطلب.'],
            ]);
        }

        return $session;
    }

    private function ensureFamilyOwnsOrder(
        Request $request,
        Order $order,
    ): int {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile !== null && $profile->productive_family_id !== null,
            403,
            'هذا الحساب غير مرتبط بأسرة منتجة.',
        );

        $familyId = (int) $profile->productive_family_id;

        $ownsOrder = Store::query()
            ->whereKey($order->store_id)
            ->where('productive_family_id', $familyId)
            ->exists();

        abort_unless($ownsOrder, 403);

        return $familyId;
    }

    private function ensureCanViewOrder(
        Request $request,
        Order $order,
    ): void {
        $guestSession = trim(
            (string) $request->header('X-Guest-Session', ''),
        );

        $allowedByGuest = $guestSession !== ''
            && hash_equals(
                (string) $order->guest_session_id,
                $guestSession,
            );

        $allowedByCustomer = $request->user() !== null
            && $order->customer_id !== null
            && $order->customer_id ===
                $request->user()->appProfile?->customer_id;

        abort_unless($allowedByGuest || $allowedByCustomer, 403);
    }

    private function newRoomName(Order $order): string
    {
        return sprintf(
            'zad-order-%d-%s',
            $order->id,
            Str::lower((string) Str::ulid()),
        );
    }

    private function preparationMinutes(Order $order): int
    {
        $productIds = $order->items()
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $minutes = $productIds->isEmpty()
            ? null
            : Product::query()
                ->whereIn('id', $productIds)
                ->max('preparation_minutes');

        $minutes = (int) ($minutes ?: 15);

        // حد تشغيلي آمن يمنع إدخال مدة صفرية أو مبالغ فيها.
        return max(1, min($minutes, 240));
    }
}