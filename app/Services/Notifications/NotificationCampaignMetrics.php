<?php

namespace App\Services\Notifications;

use App\Models\NotificationCampaign;
use App\Models\NotificationPushAttempt;
use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\DB;

class NotificationCampaignMetrics
{
    public function refresh(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId): void {
            $campaign = NotificationCampaign::query()->lockForUpdate()->find($campaignId);
            if (! $campaign || in_array($campaign->status, ['draft', 'scheduled', 'cancelled'], true)) {
                return;
            }

            $recipients = NotificationRecipient::query()->where('campaign_id', $campaignId);
            $recipientIds = (clone $recipients)->select('id');
            $attempts = NotificationPushAttempt::query()->whereIn('recipient_id', $recipientIds);

            $recipientsCount = (clone $recipients)->count();
            $inAppCount = (clone $recipients)->whereNotNull('in_app_stored_at')->count();
            $acceptedCount = (clone $attempts)->where('status', 'fcm_accepted')->count();
            $failedCount = (clone $attempts)->whereIn('status', ['failed', 'blocked'])->count();
            $openedCount = DB::table('in_app_notifications')
                ->where('campaign_id', $campaignId)
                ->whereNotNull('opened_at')
                ->count();
            $activeAttempts = (clone $attempts)
                ->whereIn('status', ['queued', 'sending', 'retrying'])
                ->count();
            $completedChunks = DB::table('notification_campaign_chunks')
                ->where('campaign_id', $campaignId)
                ->where('status', 'completed')
                ->count();
            $failedChunks = DB::table('notification_campaign_chunks')
                ->where('campaign_id', $campaignId)
                ->where('status', 'failed')
                ->count();
            $failureSummary = (clone $attempts)
                ->whereIn('status', ['failed', 'blocked'])
                ->selectRaw("COALESCE(error_code, 'unknown') as code, COUNT(*) as aggregate")
                ->groupBy('code')
                ->pluck('aggregate', 'code')
                ->map(fn ($count) => (int) $count)
                ->all();

            $finished = $campaign->audience_built_at !== null
                && $completedChunks >= $campaign->chunks_count
                && $activeAttempts === 0;

            $status = 'processing';
            if ($failedChunks > 0) {
                $status = 'failed';
            } elseif ($finished) {
                $successCount = $inAppCount + $acceptedCount;
                $status = $failedCount > 0
                    ? ($successCount > 0 ? 'partially_failed' : 'failed')
                    : 'completed';
            }

            $campaign->forceFill([
                'recipients_count' => $recipientsCount,
                'in_app_count' => $inAppCount,
                'fcm_accepted_count' => $acceptedCount,
                'failed_count' => $failedCount + $failedChunks,
                'opened_count' => $openedCount,
                'chunks_completed_count' => $completedChunks,
                'failure_summary' => $failureSummary === [] ? null : $failureSummary,
                'status' => $status,
                'completed_at' => $finished || $failedChunks > 0 ? ($campaign->completed_at ?? now()) : null,
            ])->save();
        });
    }

    public function refreshRecipient(int $recipientId): void
    {
        $recipient = NotificationRecipient::query()->find($recipientId);
        if (! $recipient) {
            return;
        }

        $attempts = $recipient->pushAttempts();
        $accepted = (clone $attempts)->where('status', 'fcm_accepted')->count();
        $failed = (clone $attempts)->whereIn('status', ['failed', 'blocked'])->count();
        $active = (clone $attempts)->whereIn('status', ['queued', 'sending', 'retrying'])->exists();

        $status = $recipient->opened_at ? 'opened' : 'completed';
        if ($active) {
            $status = 'processing';
        } elseif ($failed > 0 && $accepted === 0 && $recipient->in_app_stored_at === null) {
            $status = 'failed';
        } elseif ($failed > 0) {
            $status = 'partially_failed';
        } elseif ($accepted > 0) {
            $status = 'fcm_accepted';
        } elseif ($recipient->in_app_stored_at !== null) {
            $status = 'in_app_stored';
        }

        $recipient->forceFill([
            'status' => $status,
            'fcm_accepted_count' => $accepted,
            'push_failed_count' => $failed,
        ])->save();
    }
}
