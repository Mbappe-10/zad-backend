<?php

namespace App\Services\PhoneVerification;

use App\Contracts\OtpDeliveryProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Tawked generates and verifies its own OTP; never send locally generated OTPs. */
final class TawkedSmsOtpProvider implements OtpDeliveryProvider
{
    public function ready(): bool
    {
        $config = (array) config('zad_tawked', []);
        $key = trim((string) ($config['api_key'] ?? ''));

        $mode = (string) ($config['expected_mode'] ?? 'test');
        $baseUrl = (string) ($config['base_url'] ?? 'https://tawked.com');
        return in_array($mode, ['test', 'live'], true)
            && str_starts_with($key, 'tk_'.$mode.'_')
            && strlen($key) > strlen('tk_'.$mode.'_')
            && parse_url($baseUrl, PHP_URL_SCHEME) === 'https'
            && parse_url($baseUrl, PHP_URL_HOST) === 'tawked.com'
            && ($config['four_digits_confirmed'] ?? false) === true
            && (int) config('postpay_otp.code_length', 4) === 4;
    }

    /** Not supported. Tawked owns the code's generation and validity. */
    public function sendAuthenticationCode(
        string $phone,
        string $code,
        string $idempotencyKey,
    ): OtpDeliveryResult {
        return OtpDeliveryResult::failed('tawked_sms', 'provider_generated_code_required');
    }

    public function startVerification(string $phone, string $idempotencyKey): OtpDeliveryResult
    {
        if (! $this->ready() || ! preg_match('/^05[0-9]{8}$/D', $phone)) {
            return OtpDeliveryResult::failed('tawked_sms', 'tawked_configuration_blocked');
        }

        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->url('verify/start'), [
                    'to' => $phone,
                    'channel' => 'sms',
                    'lang' => 'ar',
                ]);
        } catch (Throwable) {
            return OtpDeliveryResult::failed('tawked_sms', 'tawked_transport_failure');
        }

        $id = $response->json('id');
        $status = $response->json('status');
        $expiry = $response->json('expires_at');
        if ($response->status() !== 201
            || ! is_string($id)
            || ! Str::isUuid($id)
            || $status !== 'pending'
            || ! is_string($expiry)) {
            return OtpDeliveryResult::failed('tawked_sms', 'tawked_send_not_confirmed');
        }
        try {
            if (\Illuminate\Support\Carbon::parse($expiry)->isPast()) {
                return OtpDeliveryResult::failed('tawked_sms', 'tawked_expired_response');
            }
        } catch (Throwable) {
            return OtpDeliveryResult::failed('tawked_sms', 'tawked_bad_expiry');
        }

        return OtpDeliveryResult::accepted('tawked_sms', $id);
    }

    /** @return array{ok:bool,verified:bool,status:string,attempts_remaining:int|null} */
    public function checkVerification(string $id, string $code): array
    {
        $failure = ['ok' => false, 'verified' => false, 'status' => 'provider_unavailable', 'attempts_remaining' => null];

        if (! $this->ready() || ! Str::isUuid($id) || ! preg_match('/^[0-9]{4}$/D', $code)) {
            return $failure;
        }

        try {
            $response = $this->request()->post($this->url('verify/check'), [
                'id' => $id,
                'code' => $code,
            ]);
        } catch (Throwable) {
            return $failure;
        }

        if ($response->status() !== 200) {
            return $failure;
        }

        $status = $response->json('status');
        $verified = $response->json('verified');
        if (! is_string($status) || ! is_bool($verified)) {
            return $failure;
        }

        $allowed = ['verified', 'invalid_code', 'too_many_attempts', 'failed', 'expired', 'canceled'];
        if (! in_array($status, $allowed, true)
            || ($verified !== ($status === 'verified'))) {
            return $failure;
        }

        $remaining = $response->json('attempts_remaining');
        return [
            'ok' => true,
            'verified' => $verified,
            'status' => $status,
            'attempts_remaining' => is_int($remaining) ? max(0, $remaining) : null,
        ];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $config = (array) config('zad_tawked', []);
        return Http::asJson()->acceptJson()
            ->withToken((string) $config['api_key'])
            ->connectTimeout(5)
            ->timeout(max(5, min((int) ($config['timeout_seconds'] ?? 10), 25)));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('zad_tawked.base_url', 'https://tawked.com'), '/')
            .'/v1/'.$path;
    }
}
