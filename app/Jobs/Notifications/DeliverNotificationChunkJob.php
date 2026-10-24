<?php

namespace App\Jobs\Notifications;

use App\Models\NotificationCampaignChunk;
use App\Services\Notifications\NotificationCampaignMetrics;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DeliverNotificationChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $chunkId)
    {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    public function handle(
        NotificationDeliveryService $delivery,
        NotificationCampaignMetrics $metrics,
    ): void {
        $chunk = DB::transaction(function (): ?NotificationCampaignChunk {
            $chunk = NotificationCampaignChunk::query()->lockForUpdate()->find($this->chunkId);
            if (! $chunk || $chunk->status === 'completed') {
                return null;
            }

            $chunk->forceFill(['status' => 'processing', 'last_error' => null])->save();

            return $chunk->fresh(['campaign']);
        });

        if (! $chunk || ! $chunk->campaign) {
            return;
        }

        foreach ((array) $chunk->recipients as $descriptor) {
            $delivery->deliverDescriptor($chunk->campaign, $descriptor);
        }

        $chunk->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $metrics->refresh($chunk->campaign_id);
    }

    public function failed(?Throwable $exception): void
    {
        $chunk = NotificationCampaignChunk::query()->find($this->chunkId);
        if (! $chunk) {
            return;
        }

        $chunk->forceFill([
            'status' => 'failed',
            'last_error' => Str::limit($exception?->getMessage() ?: 'Chunk delivery failed.', 500),
        ])->save();

        app(NotificationCampaignMetrics::class)->refresh($chunk->campaign_id);
    }
}
