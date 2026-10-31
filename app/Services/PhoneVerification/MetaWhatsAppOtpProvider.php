<?php

namespace App\Services\PhoneVerification;

use App\Contracts\OtpDeliveryProvider;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetaWhatsAppOtpProvider implements OtpDeliveryProvider
{
    public function sendAuthenticationCode(
        string $phone,
        string $code,
        string $idempotencyKey,
    ): OtpDeliveryResult {
        $config = (array) config('services.meta_whatsapp', []);
        $accessToken = trim((string) ($config['access_token'] ?? ''));
        $phoneNumberId = trim((string) ($config['phone_number_id'] ?? ''));
        $templateName = trim((string) ($config['authentication_template'] ?? ''));
        $graphVersion = trim((string) ($config['graph_version'] ?? ''));
        $language = trim((string) ($config['template_language'] ?? 'ar'));
        $approved = (bool) ($config['authentication_template_approved'] ?? false);
        $templateCodeLength = (int) ($config['authentication_template_code_length'] ?? 0);

        if (
            ! $approved
            || $accessToken === ''
            || $phoneNumberId === ''
            || $templateName === ''
            || $graphVersion === ''
            || $templateCodeLength !== strlen($code)
        ) {
            return OtpDeliveryResult::failed(
                'meta_whatsapp',
                'meta_configuration_blocked',
            );
        }

        $baseUrl = rtrim(
            (string) ($config['base_url'] ?? 'https://graph.facebook.com'),
            '/',
        );
        $recipient = ltrim($phone, '+');

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withToken($accessToken)
                ->timeout((int) ($config['timeout_seconds'] ?? 10))
                ->withHeaders([
                    'X-ZAD-Idempotency-Key' => $idempotencyKey,
                ])
                ->post(
                    "{$baseUrl}/{$graphVersion}/{$phoneNumberId}/messages",
                    [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $recipient,
                        'type' => 'template',
                        'template' => [
                            'name' => $templateName,
                            'language' => ['code' => $language],
                            'components' => [
                                [
                                    'type' => 'body',
                                    'parameters' => [
                                        ['type' => 'text', 'text' => $code],
                                    ],
                                ],
                                [
                                    'type' => 'button',
                                    'sub_type' => 'url',
                                    'index' => '0',
                                    'parameters' => [
                                        ['type' => 'text', 'text' => $code],
                                    ],
                                ],
                            ],
                        ],
                    ],
                );
        } catch (Throwable) {
            return OtpDeliveryResult::failed(
                'meta_whatsapp',
                'meta_transport_failure',
            );
        }

        if (! $response->successful()) {
            return OtpDeliveryResult::failed(
                'meta_whatsapp',
                'meta_http_'.$response->status(),
            );
        }

        $messageId = data_get($response->json(), 'messages.0.id');

        return OtpDeliveryResult::accepted(
            'meta_whatsapp',
            is_string($messageId) ? $messageId : null,
        );
    }
}
