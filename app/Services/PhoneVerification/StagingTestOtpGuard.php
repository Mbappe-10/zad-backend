<?php

namespace App\Services\PhoneVerification;

use App\Support\InternalTesting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Disabled by default. The stage-only test operator needs a separate, long
 * secret and an exact phone allow-list. Never usable against Production DB.
 */
final class StagingTestOtpGuard
{
    public static function enabled(): bool
    {
        if (! app()->environment('staging')
            || (string) config('phone_verification.provider') !== 'staging_test'
            || ! (bool) config('phone_verification.staging_test_enabled', false)
            || strlen((string) config('phone_verification.staging_test_secret', '')) < 32) {
            return false;
        }

        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql'
                || (string) $connection->getConfig('host') !== 'dpg-db783kqj9qps73bdatn0-a'
                || $connection->getDatabaseName() !== 'zad_staging') {
                return false;
            }
            $current = DB::selectOne('SELECT current_database() AS name');
            return ($current->name ?? null) === 'zad_staging';
        } catch (Throwable) {
            return false;
        }
    }

    public static function allowedPhone(string $phone): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $phones = preg_split('/[,;\s]+/', trim((string) config('phone_verification.staging_test_phones', '')));
        if (! is_array($phones)) {
            return false;
        }
        $normalized = array_map(
            static fn (string $value): string => InternalTesting::normalizePhone($value),
            array_filter($phones, static fn (string $value): bool => $value !== ''),
        );
        return in_array(InternalTesting::normalizePhone($phone), $normalized, true);
    }

    /** Deterministic only for the one-off challenge, keyed by a stage-only secret. */
    public static function codeForRequest(string $sendIdempotencyKey): string
    {
        $secret = (string) config('phone_verification.staging_test_secret', '');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('Missing staging test OTP secret');
        }
        $hex = hash_hmac('sha256', 'zad-staging-otp-v1|'.$sendIdempotencyKey, $secret);
        return (string) (100000 + (hexdec(substr($hex, 0, 12)) % 900000));
    }
}
