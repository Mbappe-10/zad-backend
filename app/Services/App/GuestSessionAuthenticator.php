<?php

namespace App\Services\App;

use App\Models\AppGuestSession;
use App\Models\PhoneVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GuestSessionAuthenticator
{
    public const HEADER = 'X-Guest-Session-Token';

    /**
     * @return array{0: string, 1: Carbon}
     */
    public function issue(AppGuestSession $guest): array
    {
        $token = $this->randomToken();
        $expiresAt = now()->addDays(
            min(
                max((int) config('phone_verification.guest_session_days', 10), 1),
                10,
            ),
        );

        $guest->forceFill([
            'session_token_hash' => $this->hashToken($token),
            'session_token_expires_at' => $expiresAt,
            'revoked_at' => null,
            'last_seen_at' => now(),
        ])->save();

        return [$token, $expiresAt];
    }

    public function tokenFromRequest(Request $request): string
    {
        $token = trim((string) $request->header(self::HEADER, ''));

        abort_if(
            $token === '',
            401,
            'Guest session authentication is required.',
        );

        return $token;
    }

    public function authenticate(
        AppGuestSession|string $guest,
        string $token,
        ?string $deviceId = null,
    ): AppGuestSession {
        $model = $guest instanceof AppGuestSession
            ? $guest
            : AppGuestSession::query()->find($guest);

        abort_if($model === null, 401, 'Guest session is invalid.');
        abort_if($model->revoked_at !== null, 401, 'Guest session was revoked.');
        abort_if(
            $model->session_token_expires_at === null
                || now()->greaterThanOrEqualTo($model->session_token_expires_at),
            401,
            'Guest session has expired.',
        );

        $expected = (string) $model->session_token_hash;

        abort_if(
            $expected === ''
                || ! hash_equals($expected, $this->hashToken($token)),
            401,
            'Guest session authentication failed.',
        );

        $submittedDeviceId = trim((string) $deviceId);
        $storedDeviceId = trim((string) $model->device_id);

        abort_if(
            $submittedDeviceId !== ''
                && $storedDeviceId !== ''
                && ! hash_equals($storedDeviceId, $submittedDeviceId),
            401,
            'Guest session does not belong to this device.',
        );

        $model->forceFill(['last_seen_at' => now()])->save();

        return $model;
    }

    public function revoke(AppGuestSession $guest): void
    {
        $revokedAt = now();

        $guest->forceFill([
            'push_token' => null,
            'revoked_at' => $revokedAt,
            'last_seen_at' => $revokedAt,
        ])->save();

        PhoneVerification::query()
            ->where('guest_session_id', $guest->getKey())
            ->whereNull('revoked_at')
            ->update([
                'status' => PhoneVerification::STATUS_REVOKED,
                'revoked_at' => $revokedAt,
                'updated_at' => $revokedAt,
            ]);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function randomToken(): string
    {
        return rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
    }
}
