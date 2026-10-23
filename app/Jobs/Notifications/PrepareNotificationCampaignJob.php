<?php

namespace App\Jobs\Notifications;

use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignChunk;
use App\Services\Notifications\NotificationAudienceService;
use App\Services\Notifications\NotificationCampaignMetrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareNotificationCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $campaignId)
    {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    public function handle(
        NotificationAudienceService $audiences,
        NotificationCampaignMetrics $metrics,
    ): void {
        $campaign = DB::transaction(function (): ?NotificationCampaign {
            $campaign = NotificationCampaign::query()->lockForUpdate()->find($this->campaignId);
            if (! $campaign || ! in_array($campaign->status, ['queued', 'scheduled'], true)) {
                return null;
            }

            if ($campaign->scheduled_at?->isFuture()) {
                return null;
            }

            $campaign->forceFill([
                'status' => 'processing',
                'processing_at' => $campaign->processing_at ?? now(),
            ])->save();

            return $campaign->fresh();
        });

        if (! $campaign) {
            return;
        }

        $sequence = 0;
        $audiences->eachEligibleChunk($campaign, function (array $descriptors) use ($campaign, &$sequence): void {
            $sequence++;
            $actorKeys = array_column($descriptors, 'recipient_key');
            $chunkKey = hash('sha256', $campaign->id.'|'.$sequence.'|'.implode('|', $actorKeys));
            $chunk = NotificationCampaignChunk::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'chunk_key' => $chunkKey],
                [
                    'status' => 'queued',
                    'recipients' => $descriptors,
                    'recipients_count' => count($descriptors),
                ],
            );

            DeliverNotificationChunkJob::dispatch($chunk->id)
                ->onQueue((string) config('notifications.queue', 'notifications'));
        });

        $campaign->forceFill([
            'audience_built_at' => now(),
            'chunks_count' => NotificationCampaignChunk::query()->where('campaign_id', $campaign->id)->count(),
        ])->save();

        $metrics->refresh($campaign->id);
    }

    public function failed(?Throwable $exception): void
    {
        NotificationCampaign::query()->whereKey($this->campaignId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'failure_summary' => ['audience_build_failed' => 1],
        ]);
    }
}
