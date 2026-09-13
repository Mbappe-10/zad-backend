<?php

namespace App\Support;

final class InternalTesting
{
    public static function enabled(): bool
    {
        return (bool) config('internal_testing.enabled', false);
    }

    public static function restrictRoleLogin(): bool
    {
        return self::enabled()
            && (bool) config(
                'internal_testing.restrict_role_login',
                true,
            );
    }

    public static function otpExpiresMinutes(): int
    {
        return max(
            1,
            min(
                10,
                (int) config(
                    'internal_testing.otp.expires_minutes',
                    3,
                ),
            ),
        );
    }

    public static function exposesOtpFor(string $phone): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return self::enabled()
            && (bool) config(
                'internal_testing.otp.expose_code',
                false,
            )
            && self::phoneIsAllowed($phone);
    }

    public static function simulatesPaymentFor(string $phone): bool
    {
        return self::enabled()
            && (bool) config(
                'internal_testing.payment.simulate_success',
                false,
            )
            && self::phoneIsAllowed($phone);
    }

    public static function phoneIsAllowed(string $phone): bool
    {
        $normalized = self::normalizePhone($phone);

        if ($normalized === '') {
            return false;
        }

        $allowed = config(
            'internal_testing.otp.allowed_phones',
            [],
        );

        if (! is_array($allowed)) {
            return false;
        }

        return collect($allowed)
            ->map(
                static fn (mixed $value): string =>
                    self::normalizePhone((string) $value),
            )
            ->filter()
            ->contains($normalized);
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '9665') && strlen($digits) === 12) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            return '+966'.substr($digits, 1);
        }

        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            return '+966'.$digits;
        }

        return $digits === '' ? '' : '+'.$digits;
    }
}
