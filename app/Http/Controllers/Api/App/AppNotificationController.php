<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationActor;
use App\Services\Notifications\NotificationActorResolver;
use App\Services\Notifications\NotificationCampaignMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppNotificationController extends Controller
{
    public function index(Request $request, NotificationActorResolver $actors): JsonResponse
    {
        $actor = $actors->resolve($request);
        $query = $this->actorQuery($actor)->latest('id');
        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }
        $paginator = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (InAppNotification $notification) => $this->notificationData($notification))->values(),
            'unread_count' => $this->actorQuery($actor)->whereNull('read_at')->count(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function open(
        Request $request,
        InAppNotification $notification,
        NotificationActorResolver $actors,
        NotificationCampaignMetrics $metrics,
    ): JsonResponse {
        $actor = $actors->resolve($request);
        $this->authorizeOwner($notification, $actor);
        $campaignId = $notification->campaign_id;
        $recipientId = $notification->recipient_id;

        DB::transaction(function () use ($notification): void {
            $locked = InAppNotification::query()->lockForUpdate()->findOrFail($notification->id);
            $locked->forceFill([
                'read_at' => $locked->read_at ?? now(),
                'opened_at' => $locked->opened_at ?? now(),
            ])->save();

            if ($locked->recipient_id) {
                DB::table('notification_recipients')
                    ->where('id', $locked->recipient_id)
                    ->whereNull('opened_at')
                    ->update(['opened_at' => now(), 'status' => 'opened', 'updated_at' => now()]);
            }
        }, 3);

        if ($recipientId) {
            $metrics->refreshRecipient((int) $recipientId);
        }
        if ($campaignId) {
            $metrics->refresh((int) $campaignId);
        }

        return response()->json(['data' => $this->notificationData($notification->fresh())]);
    }

    public function preferences(Request $request, NotificationActorResolver $actors): JsonResponse
    {
        $actor = $actors->resolve($request);
        $preference = NotificationPreference::query()->where('actor_key', $actor->key())->first();

        return response()->json(['data' => $this->preferenceData($preference)]);
    }

    public function updatePreferences(Request $request, NotificationActorResolver $actors): JsonResponse
    {
        $data = $request->validate([
            'in_app_enabled' => ['sometimes', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            'marketing_enabled' => ['sometimes', 'boolean'],
        ]);
        abort_if($data === [], 422, 'At least one preference is required.');
        $actor = $actors->resolve($request);
        $preference = NotificationPreference::query()->firstOrNew(['actor_key' => $actor->key()]);
        $preference->fill([
            'user_id' => $actor->user?->id,
            'guest_session_id' => $actor->guest?->id,
            'in_app_enabled' => $data['in_app_enabled'] ?? ($preference->in_app_enabled ?? true),
            'push_enabled' => $data['push_enabled'] ?? ($preference->push_enabled ?? true),
            'marketing_enabled' => $data['marketing_enabled'] ?? ($preference->marketing_enabled ?? false),
        ])->save();

        if (array_key_exists('push_enabled', $data) || array_key_exists('marketing_enabled', $data)) {
            $devices = DB::table('notification_device_tokens')
                ->when($actor->user, fn ($query) => $query->where('user_id', $actor->user->id))
                ->when($actor->guest, fn ($query) => $query->where('guest_session_id', $actor->guest->id));
            $updates = ['updated_at' => now()];
            if (array_key_exists('push_enabled', $data)) {
                $updates['push_enabled'] = (bool) $data['push_enabled'];
            }
            if (array_key_exists('marketing_enabled', $data)) {
                $updates['marketing_enabled'] = (bool) $data['marketing_enabled'];
            }
            $devices->update($updates);
        }

        return response()->json(['data' => $this->preferenceData($preference->fresh())]);
    }

    private function actorQuery(NotificationActor $actor): Builder
    {
        return InAppNotification::query()->when(
            $actor->user,
            fn (Builder $query) => $query->where('user_id', $actor->user->id),
            fn (Builder $query) => $query->where('guest_session_id', $actor->guest?->id),
        );
    }

    private function authorizeOwner(InAppNotification $notification, NotificationActor $actor): void
    {
        $owned = $actor->user
            ? (int) $notification->user_id === (int) $actor->user->id
            : (string) $notification->guest_session_id === (string) $actor->guest?->id;
        abort_unless($owned, 404);
    }

    private function notificationData(InAppNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'locale' => $notification->locale,
            'title' => $notification->title,
            'body' => $notification->body,
            'image_url' => $notification->image_url,
            'action_url' => $notification->action_url,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'opened_at' => $notification->opened_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    private function preferenceData(?NotificationPreference $preference): array
    {
        return [
            'in_app_enabled' => $preference?->in_app_enabled ?? true,
            'push_enabled' => $preference?->push_enabled ?? true,
            'marketing_enabled' => $preference?->marketing_enabled ?? false,
        ];
    }
}
