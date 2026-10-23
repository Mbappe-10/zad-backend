<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\DispatchNotificationPushJob;
use App\Models\InAppNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationDeviceToken;
use App\Models\NotificationPushAttempt;
use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryService
{
    public function deliverDescriptor(NotificationCampaign $campaign, array $descriptor): void
    {
        $attemptIds = DB::transaction(function () use ($campaign, $descriptor): array {
            $recipient = NotificationRecipient::query()->firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'recipient_key' => $descriptor['recipient_key'],
                ],
                [
                    'user_id' => $descriptor['user_id'] ?? null,
                    'guest_session_id' => $descriptor['guest_session_id'] ?? null,
                    'audience_role' => $descriptor['audience_role'],
                    'status' => 'processing',
                ],
            );

            if (($descriptor['in_app'] ?? false) && $recipient->in_app_stored_at === null) {
                $key = hash('sha256', 'campaign:'.$campaign->id.'|'.$recipient->recipient_key.'|in-app');
                InAppNotification::query()->firstOrCreate(
                    ['idempotency_key' => $key],
                    [
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'template_id' => $campaign->template_id,
                        'user_id' => $recipient->user_id,
                        'guest_session_id' => $recipient->guest_session_id,
                        'type' => $campaign->type,
                        'locale' => $campaign->locale,
                        'title' => $campaign->title,
                        'body' => $campaign->body,
                        'image_url' => $campaign->image_url,
                        'action_url' => $campaign->action_url,
                        'data' => $campaign->data,
                    ],
                );
                $recipient->forceFill(['in_app_stored_at' => now()])->save();
            }

            $devices = ($descriptor['push_devices'] ?? 0) > 0
                ? $this->deviceQuery($campaign, $recipient)->get()
                : collect();
            $attemptIds = [];
            foreach ($devices as $device) {
                $attempt = NotificationPushAttempt::query()->firstOrCreate(
                    [
                        'recipient_id' => $recipient->id,
                        'device_token_id' => $device->id,
                    ],
                    [
                        'idempotency_key' => hash('sha256', 'campaign:'.$campaign->id.'|recipient:'.$recipient->id.'|device:'.$device->id),
                        'status' => 'queued',
                    ],
                );

                if (in_array($attempt->status, ['queued', 'retrying'], true)) {
                    $attemptIds[] = $attempt->id;
                }
            }

            $recipient->forceFill([
                'push_devices_count' => $devices->count(),
                'status' => $attemptIds === []
                    ? ($recipient->in_app_stored_at ? 'in_app_stored' : 'completed')
                    : 'processing',
            ])->save();

            return $attemptIds;
        }, 3);

        foreach (array_unique($attemptIds) as $attemptId) {
            DispatchNotificationPushJob::dispatch($attemptId)
                ->onQueue((string) config('notifications.queue', 'notifications'));
        }
    }

    private function deviceQuery(NotificationCampaign $campaign, NotificationRecipient $recipient): Builder
    {
        return NotificationDeviceToken::query()
            ->whereNull('revoked_at')
            ->where('permission_granted', true)
            ->where('push_enabled', true)
            ->when($campaign->type === 'marketing', fn (Builder $query) => $query->where('marketing_enabled', true))
            ->when(
                $recipient->user_id !== null,
                fn (Builder $query) => $query->where('user_id', $recipient->user_id),
                fn (Builder $query) => $query->where('guest_session_id', $recipient->guest_session_id),
            );
    }
}
