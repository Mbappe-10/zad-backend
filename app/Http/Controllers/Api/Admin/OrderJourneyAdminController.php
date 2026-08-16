<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\JourneyRetentionSetting;
use App\Models\Order;
use App\Models\OrderJourneyProof;
use App\Models\OrderLiveSession;
use App\Services\OrderJourneyRetentionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderJourneyAdminController extends Controller
{
    public function __construct(
        private readonly OrderJourneyRetentionService $retention,
    ) {
    }

    public function journey(Request $request, Order $order): JsonResponse
    {
        $this->ensurePlatformOwner($request);
        $this->retention->schedule($order);

        $order->load([
            'items',
            'store',
            'customer',
            'driver',
            'history.changedBy:id,name',
            'assignments.driver',
        ]);

        $proofs = OrderJourneyProof::query()
            ->where('order_id', $order->id)
            ->oldest('created_at')
            ->get()
            ->map(fn (OrderJourneyProof $proof): array => [
                'id' => $proof->id,
                'stage' => $proof->stage,
                'photo_url' => $this->publicUrl($proof->photo_path),
                'photo_available' => filled($proof->photo_path),
                'photo_purged_at' => $proof->photo_purged_at,
                'photo_checksum' => $proof->photo_checksum,
                'photo_size_bytes' => $proof->photo_size_bytes,
                'photo_mime_type' => $proof->photo_mime_type,
                'latitude' => $proof->latitude,
                'longitude' => $proof->longitude,
                'note' => $proof->note,
                'uploaded_by' => $proof->uploaded_by,
                'created_at' => $proof->created_at,
            ])
            ->values();

        $liveSessions = OrderLiveSession::query()
            ->where('order_id', $order->id)
            ->with(['startedBy:id,name', 'adminUpdatedBy:id,name'])
            ->oldest('created_at')
            ->get()
            ->map(fn (OrderLiveSession $session): array => [
                'id' => $session->id,
                'public_id' => $session->public_id,
                'status' => $session->status,
                'viewer_count' => $session->viewer_count,
                'peak_viewers' => $session->peak_viewers,
                'quality_profile' => $session->quality_profile,
                'compliance' => $session->compliance,
                'metadata' => $session->metadata,
                'started_at' => $session->started_at,
                'paused_at' => $session->paused_at,
                'resumed_at' => $session->resumed_at,
                'ended_at' => $session->ended_at,
                'ended_reason' => $session->ended_reason,
                'scheduled_end_at' => $session->scheduled_end_at,
                'preparation_minutes' => $session->preparation_minutes,
                'grace_minutes' => $session->grace_minutes,
                'extended_minutes' => $session->extended_minutes,
                'final_photo_url' => $this->publicUrl($session->final_photo_path),
                'final_photo_available' => filled($session->final_photo_path),
                'final_photo_purged_at' => $session->final_photo_purged_at,
                'final_photo_checksum' => $session->final_photo_checksum,
                'started_by' => $session->startedBy,
                'admin_updated_by' => $session->adminUpdatedBy,
                'admin_action_reason' => $session->admin_action_reason,
            ])
            ->values();

        return response()->json([
            'data' => [
                'order' => $order,
                'proofs' => $proofs,
                'live_sessions' => $liveSessions,
                'retention' => $this->retentionPayload($order->fresh()),
            ],
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        return response()->json([
            'data' => $this->settingsPayload(JourneyRetentionSetting::current()),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $data = $request->validate([
            'completed_retention_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'cancelled_retention_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'problem_retention_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'purge_batch_size' => ['required', 'integer', 'min:1', 'max:500'],
            'automatic_purge_enabled' => ['required', 'boolean'],
        ]);

        $settings = JourneyRetentionSetting::current();
        $settings->forceFill([
            ...$data,
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        return response()->json([
            'message' => 'تم حفظ إعدادات الاحتفاظ بوسائط رحلات الطلبات.',
            'data' => $this->settingsPayload($settings->fresh()),
        ]);
    }

    public function hold(Request $request, Order $order): JsonResponse
    {
        $this->ensurePlatformOwner($request);
        $this->ensureNotPurged($order);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'hold_until' => ['nullable', 'date', 'after:now'],
        ]);

        $order = $this->retention->hold(
            $order,
            $data['reason'],
            isset($data['hold_until']) ? Carbon::parse($data['hold_until']) : null,
            $request->user()->id,
        );

        return response()->json([
            'message' => 'تم تجميد حذف وسائط رحلة الطلب.',
            'data' => $this->retentionPayload($order),
        ]);
    }

    public function release(Request $request, Order $order): JsonResponse
    {
        $this->ensurePlatformOwner($request);
        $this->ensureNotPurged($order);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $order = $this->retention->release(
            $order,
            $data['reason'],
            $request->user()->id,
        );

        return response()->json([
            'message' => 'تم فك تجميد الوسائط وتحديد موعد حذف جديد.',
            'data' => $this->retentionPayload($order),
        ]);
    }

    public function extend(Request $request, Order $order): JsonResponse
    {
        $this->ensurePlatformOwner($request);
        $this->ensureNotPurged($order);

        $data = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $order = $this->retention->extend(
            $order,
            (int) $data['hours'],
            $data['reason'],
            $request->user()->id,
        );

        return response()->json([
            'message' => 'تم تمديد مدة الاحتفاظ بوسائط الطلب.',
            'data' => $this->retentionPayload($order),
        ]);
    }

    public function purge(Request $request, Order $order): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'confirmation' => ['required', 'in:PURGE'],
        ]);

        $order = $this->retention->purge(
            $order,
            $request->user()->id,
            $data['reason'],
        );

        return response()->json([
            'message' => 'تم حذف وسائط رحلة الطلب مع إبقاء السجل والتدقيق.',
            'data' => $this->retentionPayload($order),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->ensurePlatformOwner($request);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'exists:orders,id'],
            'action' => ['required', 'in:hold,release,extend,purge'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'hours' => ['nullable', 'required_if:action,extend', 'integer', 'min:1', 'max:8760'],
            'hold_until' => ['nullable', 'date', 'after:now'],
            'confirmation' => ['nullable', 'required_if:action,purge', 'in:PURGE'],
        ]);

        $orders = Order::query()->whereIn('id', $data['order_ids'])->get();
        $results = [];

        foreach ($orders as $order) {
            try {
                if ($data['action'] !== 'purge') {
                    $this->ensureNotPurged($order);
                }

                $updated = match ($data['action']) {
                    'hold' => $this->retention->hold(
                        $order,
                        $data['reason'],
                        isset($data['hold_until']) ? Carbon::parse($data['hold_until']) : null,
                        $request->user()->id,
                    ),
                    'release' => $this->retention->release(
                        $order,
                        $data['reason'],
                        $request->user()->id,
                    ),
                    'extend' => $this->retention->extend(
                        $order,
                        (int) $data['hours'],
                        $data['reason'],
                        $request->user()->id,
                    ),
                    'purge' => $this->retention->purge(
                        $order,
                        $request->user()->id,
                        $data['reason'],
                    ),
                };

                $results[] = [
                    'order_id' => $order->id,
                    'number' => $order->number,
                    'status' => 'succeeded',
                    'retention' => $this->retentionPayload($updated),
                ];
            } catch (Throwable $exception) {
                report($exception);
                $results[] = [
                    'order_id' => $order->id,
                    'number' => $order->number,
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'اكتمل تنفيذ الإجراء الجماعي.',
            'data' => $results,
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

    private function ensureNotPurged(Order $order): void
    {
        if ($order->media_retention_status === 'purged') {
            throw ValidationException::withMessages([
                'order' => ['حُذفت وسائط هذا الطلب بالفعل ولا يمكن تمديدها أو تجميدها.'],
            ]);
        }
    }

    private function publicUrl(?string $path): ?string
    {
        return filled($path) ? Storage::disk('public')->url($path) : null;
    }

    private function settingsPayload(JourneyRetentionSetting $settings): array
    {
        return [
            'completed_retention_hours' => $settings->completed_retention_hours,
            'cancelled_retention_hours' => $settings->cancelled_retention_hours,
            'problem_retention_hours' => $settings->problem_retention_hours,
            'purge_batch_size' => $settings->purge_batch_size,
            'automatic_purge_enabled' => $settings->automatic_purge_enabled,
            'updated_at' => $settings->updated_at,
        ];
    }

    private function retentionPayload(Order $order): array
    {
        return [
            'status' => $order->media_retention_status,
            'delete_at' => $order->media_delete_at,
            'on_hold' => (bool) $order->media_retention_hold,
            'hold_until' => $order->media_hold_until,
            'reason' => $order->media_retention_reason,
            'purged_at' => $order->media_purged_at,
            'purged_by_user_id' => $order->media_purged_by_user_id,
            'purge_attempts' => (int) $order->media_purge_attempts,
            'last_error' => $order->media_purge_last_error,
        ];
    }
}