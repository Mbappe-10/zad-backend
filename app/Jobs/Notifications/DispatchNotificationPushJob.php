<?php

namespace App\Jobs\Notifications;

use App\Exceptions\NotificationProviderBlockedException;
use App\Exceptions\FcmSendException;
use App\Models\NotificationPushAttempt;
use App\Services\Notifications\FcmClient;
use App\Services\Notifications\NotificationCampaignMetrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchNotificationPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $attemptId)
    {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    public function handle(FcmClient $fcm, NotificationCampaignMetrics $metrics): void
    {
        $attempt = DB::transaction(function (): ?NotificationPushAttempt {
            $attempt = NotificationPushAttempt::query()->lockForUpdate()->find($this->attemptId);
            if (! $attempt || in_array($attempt->status, ['fcm_accepted', 'failed', 'blocked', 'skipped'], true)) {
                return null;
            }

            $attempt->forceFill([
                'status' => 'sending',
                'attempts' => $attempt->attempts + 1,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return $attempt->fresh(['device', 'recipient.campaign']);
        });

        if (! $attempt) {
            return;
        }

        $campaign = $attempt->recipient?->campaign;
        $device = $attempt->device;
        if (! $campaign || ! $device || ! $device->isEligibleFor($campaign->type)) {
            $attempt->forceFill([
                'status' => 'skipped',
                'error_code' => 'device_not_eligible',
                'error_message' => 'The device is revoked, disabled, or no longer eligible.',
                'failed_at' => now(),
            ])->save();
            $this->refresh($attempt, $metrics);

            return;
        }

        try {
            $inAppId = $attempt->recipient?->inAppNotification?->id;
            $result = $fcm->send($device, $campaign, $inAppId === null ? null : (int) $inAppId);
            $attempt->forceFill([
                'status' => 'fcm_accepted',
                'provider_message_id' => $result['message_id'],
                'accepted_at' => now(),
                'failed_at' => null,
            ])->save();
        } catch (NotificationProviderBlockedException $exception) {
            $attempt->forceFill([
                'status' => 'blocked',
                'error_code' => 'fcm_not_configured',
                'error_message' => Str::limit($exception->getMessage(), 500),
                'failed_at' => now(),
            ])->save();
        } catch (FcmSendException $exception) {
            if ($exception->invalidToken) {
                $device->forceFill([
                    'token_ciphertext' => null,
                    'permission_granted' => false,
                    'push_enabled' => false,
                    'revoked_at' => now(),
                ])->save();
            }

            if ($exception->retryable && $attempt->attempts < 3) {
                $attempt->forceFill([
                    'status' => 'retrying',
                    'error_code' => $exception->providerCode,
                    'error_message' => 'FCM temporarily rejected the message.',
                ])->save();

                self::dispatch($attempt->id)
                    ->delay(now()->addSeconds($attempt->attempts === 1 ? 30 : 120))
                    ->onQueue((string) config('notifications.queue', 'notifications'));
            } else {
                $attempt->forceFill([
                    'status' => 'failed',
                    'error_code' => $exception->providerCode,
                    'error_message' => $exception->invalidToken
                        ? 'The device token is invalid or unregistered and was revoked.'
                        : 'FCM rejected the message.',
                    'failed_at' => now(),
                ])->save();
            }
        } catch (Throwable $exception) {
            if ($attempt->attempts < 3) {
                $attempt->forceFill([
                    'status' => 'retrying',
                    'error_code' => 'fcm_send_failed',
                    'error_message' => 'The FCM request failed before acceptance.',
                ])->save();

                self::dispatch($attempt->id)
                    ->delay(now()->addSeconds($attempt->attempts === 1 ? 30 : 120))
                    ->onQueue((string) config('notifications.queue', 'notifications'));
            } else {
                $attempt->forceFill([
                    'status' => 'failed',
                    'error_code' => 'fcm_send_failed',
                    'error_message' => 'The FCM request failed before acceptance.',
                    'failed_at' => now(),
                ])->save();
            }
        }

        $this->refresh($attempt, $metrics);
    }

    private function refresh(NotificationPushAttempt $attempt, NotificationCampaignMetrics $metrics): void
    {
        $metrics->refreshRecipient($attempt->recipient_id);
        $campaignId = $attempt->recipient?->campaign_id
            ?? $attempt->recipient()->value('campaign_id');
        if ($campaignId) {
            $metrics->refresh((int) $campaignId);
        }
    }
}
