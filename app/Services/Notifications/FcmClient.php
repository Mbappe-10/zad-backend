<?php

namespace App\Services\Notifications;

use App\Exceptions\NotificationProviderBlockedException;
use App\Exceptions\FcmSendException;
use App\Models\NotificationCampaign;
use App\Models\NotificationDeviceToken;
use Google\Client as GoogleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class FcmClient
{
    public function configurationStatus(): array
    {
        $enabled = (bool) config('notifications.fcm.enabled', false);
        $projectId = trim((string) config('notifications.fcm.project_id'));
        $credentials = $this->credentials(false);

        return [
            'enabled' => $enabled,
            'configured' => $projectId !== '' && $credentials !== null,
            'state' => $enabled && $projectId !== '' && $credentials !== null
                ? 'ready'
                : 'blocked',
        ];
    }

    /** @return array{message_id: string|null, accepted: true} */
    public function send(NotificationDeviceToken $device, NotificationCampaign $campaign, ?int $inAppNotificationId = null): array
    {
        $status = $this->configurationStatus();
        if (! $status['enabled'] || ! $status['configured']) {
            throw new NotificationProviderBlockedException();
        }

        $token = trim((string) $device->token_ciphertext);
        if ($token === '') {
            throw new RuntimeException('The registered push token is unavailable.');
        }

        $projectId = trim((string) config('notifications.fcm.project_id'));
        $accessToken = $this->accessToken();
        $notification = [
            'title' => $campaign->title,
            'body' => $campaign->body,
        ];

        if ($campaign->image_url) {
            $notification['image'] = $campaign->image_url;
        }

        $data = collect((array) $campaign->data)
            ->mapWithKeys(fn (mixed $value, string $key): array => [$key => is_scalar($value) ? (string) $value : json_encode($value)])
            ->all();
        $data['campaign_id'] = (string) $campaign->id;
        if ($inAppNotificationId !== null) {
            $data['in_app_notification_id'] = (string) $inAppNotificationId;
        }
        $data['notification_type'] = (string) $campaign->type;
        if ($campaign->action_url) {
            $data['action_url'] = $campaign->action_url;
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withToken($accessToken)
                ->timeout((int) config('notifications.fcm.timeout_seconds', 10))
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => $notification,
                        'data' => $data,
                        'android' => [
                            'notification' => array_filter([
                                'icon' => 'ic_notification',
                                'color' => '#E87817',
                                'image' => $campaign->image_url,
                            ]),
                        ],
                        'apns' => [
                            'payload' => ['aps' => ['mutable-content' => 1]],
                            'fcm_options' => array_filter(['image' => $campaign->image_url]),
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The FCM endpoint could not be reached.', previous: $exception);
        }

        if (! $response->successful()) {
            $providerCode = (string) (
                data_get($response->json(), 'error.details.0.errorCode')
                ?: data_get($response->json(), 'error.status', 'FCM_REJECTED')
            );
            $invalidToken = in_array($providerCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true);
            $retryable = in_array($providerCode, ['UNAVAILABLE', 'INTERNAL', 'QUOTA_EXCEEDED'], true);
            throw new FcmSendException($providerCode, $retryable, $invalidToken);
        }

        return [
            'accepted' => true,
            'message_id' => $response->json('name'),
        ];
    }

    private function accessToken(): string
    {
        $client = new GoogleClient();
        $client->setAuthConfig($this->credentials(true));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $token = $client->fetchAccessTokenWithAssertion();

        if (isset($token['error']) || empty($token['access_token'])) {
            throw new NotificationProviderBlockedException('FCM service-account authentication failed.');
        }

        return (string) $token['access_token'];
    }

    /** @return array<string, mixed>|null */
    private function credentials(bool $required): ?array
    {
        $raw = trim((string) config('notifications.fcm.credentials_json'));
        $base64 = trim((string) config('notifications.fcm.credentials_base64'));
        $path = trim((string) config('notifications.fcm.credentials_path'));

        try {
            if ($raw !== '') {
                $credentials = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } elseif ($base64 !== '') {
                $decoded = base64_decode($base64, true);
                $credentials = $decoded === false
                    ? null
                    : json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            } elseif ($path !== '' && is_readable($path)) {
                $contents = file_get_contents($path);
                $credentials = $contents === false
                    ? null
                    : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } else {
                $credentials = null;
            }
        } catch (JsonException) {
            $credentials = null;
        }

        if (
            ! is_array($credentials)
            || ($credentials['type'] ?? null) !== 'service_account'
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])
        ) {
            if ($required) {
                throw new NotificationProviderBlockedException('A valid FCM service-account credential is required.');
            }

            return null;
        }

        return $credentials;
    }
}
