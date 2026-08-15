<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveBroadcastSetting;
use App\Models\OrderLiveSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LiveBroadcastAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $data = $request->validate([
            'status' => ['nullable', 'in:active,waiting,live,paused,ended,all'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $status = $data['status'] ?? 'active';
        $search = trim((string) ($data['search'] ?? ''));

        $query = OrderLiveSession::query()
            ->with([
                'order:id,number,status,total,store_id',
                'order.store:id,productive_family_id,name_ar,name_en',
            ])
            ->when($status === 'active', fn (Builder $query): Builder =>
                $query->whereIn('status', [
                    OrderLiveSession::STATUS_WAITING,
                    OrderLiveSession::STATUS_LIVE,
                    OrderLiveSession::STATUS_PAUSED,
                ]))
            ->when($status !== 'active' && $status !== 'all', fn (Builder $query): Builder =>
                $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('public_id', 'like', "%{$search}%")
                        ->orWhereHas('order', fn (Builder $order): Builder =>
                            $order->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('order.store', fn (Builder $store): Builder =>
                            $store->where('name_ar', 'like', "%{$search}%")
                                ->orWhere('name_en', 'like', "%{$search}%"));
                });
            })
            ->latest('id');

        $sessions = $query->limit(100)->get()
            ->map(fn (OrderLiveSession $session): array => $this->sessionPayload($session))
            ->values();

        return response()->json([
            'data' => [
                'stats' => [
                    'live' => OrderLiveSession::query()->where('status', 'live')->count(),
                    'paused' => OrderLiveSession::query()->where('status', 'paused')->count(),
                    'ended_today' => OrderLiveSession::query()
                        ->where('status', 'ended')
                        ->whereDate('ended_at', today())
                        ->count(),
                    'violations_today' => OrderLiveSession::query()
                        ->whereDate('updated_at', today())
                        ->whereNotNull('admin_action_reason')
                        ->count(),
                ],
                'sessions' => $sessions,
                'settings' => $this->settingsPayload(LiveBroadcastSetting::current()),
            ],
        ]);
    }

    public function extend(Request $request, OrderLiveSession $session): JsonResponse
    {
        $this->ensurePlatformOwner($request);
        $settings = LiveBroadcastSetting::current();

        $data = $request->validate([
            'minutes' => [
                'required',
                'integer',
                'min:1',
                'max:'.$settings->maximum_extension_minutes,
            ],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if (! $session->isWatchable()) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تمديد جلسة بث منتهية.'],
            ]);
        }

        $baseEnd = $session->scheduled_end_at ?? now();
        $session->forceFill([
            'scheduled_end_at' => $baseEnd->copy()->addMinutes((int) $data['minutes']),
            'extended_minutes' => (int) $session->extended_minutes + (int) $data['minutes'],
            'admin_updated_by_user_id' => $request->user()->id,
            'admin_action_reason' => $data['reason'],
        ])->save();

        return response()->json([
            'message' => 'تم تمديد البث بنجاح.',
            'data' => $this->sessionPayload($session->fresh(['order.store'])),
        ]);
    }

    public function end(Request $request, OrderLiveSession $session): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($session->status === OrderLiveSession::STATUS_ENDED) {
            throw ValidationException::withMessages([
                'session' => ['جلسة البث منتهية بالفعل.'],
            ]);
        }

        $session->forceFill([
            'status' => OrderLiveSession::STATUS_ENDED,
            'ended_at' => now(),
            'ended_reason' => 'admin_ended',
            'last_heartbeat_at' => now(),
            'admin_updated_by_user_id' => $request->user()->id,
            'admin_action_reason' => $data['reason'],
        ])->save();

        return response()->json([
            'message' => 'تم إيقاف البث إداريًا.',
            'data' => $this->sessionPayload($session->fresh(['order.store'])),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $data = $request->validate([
            'default_grace_minutes' => ['required', 'integer', 'min:0', 'max:30'],
            'warning_before_end_minutes' => ['required', 'integer', 'min:1', 'max:15'],
            'maximum_extension_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'auto_end_enabled' => ['required', 'boolean'],
            'audio_enabled' => ['required', 'boolean', 'declined'],
            'chat_enabled' => ['required', 'boolean', 'declined'],
            'screen_share_enabled' => ['required', 'boolean', 'declined'],
        ]);

        $settings = LiveBroadcastSetting::current();
        $settings->forceFill([
            ...$data,
            'audio_enabled' => false,
            'chat_enabled' => false,
            'screen_share_enabled' => false,
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        return response()->json([
            'message' => 'تم حفظ إعدادات البث.',
            'data' => $this->settingsPayload($settings->fresh()),
        ]);
    }

    private function ensurePlatformOwner(Request $request): void
    {
        $user = $request->user();
        $owner = $user !== null && (
            $user->getAttribute('role') === 'platform_owner'
            || $user->getAttribute('account_type') === 'platform_owner'
            || $user->getAttribute('is_platform_owner') === true
            || (method_exists($user, 'hasRole') && $user->hasRole('platform_owner'))
        );

        abort_unless($owner, 403, 'هذه العملية متاحة لمالك المنصة فقط.');
    }

    private function settingsPayload(LiveBroadcastSetting $settings): array
    {
        return [
            'default_grace_minutes' => $settings->default_grace_minutes,
            'warning_before_end_minutes' => $settings->warning_before_end_minutes,
            'maximum_extension_minutes' => $settings->maximum_extension_minutes,
            'auto_end_enabled' => $settings->auto_end_enabled,
            'audio_enabled' => false,
            'chat_enabled' => false,
            'screen_share_enabled' => false,
        ];
    }

    private function sessionPayload(OrderLiveSession $session): array
    {
        $order = $session->order;
        $store = $order?->store;
        $remainingSeconds = $session->scheduled_end_at
            ? max(0, now()->diffInSeconds($session->scheduled_end_at, false))
            : 0;

        return [
            'id' => $session->id,
            'public_id' => $session->public_id,
            'status' => $session->status,
            'viewer_count' => $session->viewer_count,
            'peak_viewers' => $session->peak_viewers,
            'started_at' => $session->started_at,
            'ended_at' => $session->ended_at,
            'scheduled_end_at' => $session->scheduled_end_at,
            'preparation_minutes' => $session->preparation_minutes,
            'grace_minutes' => $session->grace_minutes,
            'extended_minutes' => $session->extended_minutes,
            'remaining_seconds' => $remainingSeconds,
            'final_photo_url' => $session->final_photo_path
                ? Storage::disk('public')->url($session->final_photo_path)
                : null,
            'order' => [
                'id' => $order?->id,
                'number' => $order?->number ?? '-',
                'status' => $order?->status ?? '-',
                'total' => (float) ($order?->total ?? 0),
            ],
            'family' => $store ? [
                'id' => $session->productive_family_id,
                'name' => $store->name_ar ?: $store->name_en,
            ] : null,
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name_ar ?: $store->name_en,
            ] : null,
        ];
    }
}