<?php

namespace App\Services\App;

use Google\Client as GoogleClient;
use Illuminate\Validation\ValidationException;

class GoogleIdentityVerifier
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $idToken): array
    {
        $clientId = trim((string) config('services.google.client_id'));

        if ($clientId === '') {
            throw ValidationException::withMessages([
                'id_token' => [
                    'تسجيل Google غير مهيأ على الخادم حاليًا.',
                ],
            ]);
        }

        try {
            $payload = (new GoogleClient([
                'client_id' => $clientId,
            ]))->verifyIdToken($idToken);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'id_token' => [
                    'تعذر التحقق من حساب Google. حاول مرة أخرى.',
                ],
            ]);
        }

        if (! is_array($payload) || empty($payload['sub'])) {
            throw ValidationException::withMessages([
                'id_token' => [
                    'تعذر التحقق من حساب Google. حاول مرة أخرى.',
                ],
            ]);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $emailVerified = filter_var(
            $payload['email_verified'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        if ($email === '' || ! $emailVerified) {
            throw ValidationException::withMessages([
                'id_token' => [
                    'يجب استخدام بريد Google موثق لربط حساب زاد سينك.',
                ],
            ]);
        }

        $payload['email'] = $email;

        return $payload;
    }
}
