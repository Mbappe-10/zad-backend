<?php

namespace App\Services\App;

use App\Models\PlatformControl;
use Illuminate\Validation\ValidationException;

/**
 * ZAD Moyasar modes: server-side source of truth. Never use client-provided mode.
 * Non-live operations must run against an isolated NON-PRODUCTION database.
 */
final class MoyasarModeResolver
{
    public function currentMode(): string
    {
        $sections = PlatformControl::cachedValues();
        $services = is_array($sections['services'] ?? null)
            ? $sections['services']
            : [];

        if (! filter_var($services['paymentGatewayEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return 'disabled';
        }

        $mode = (string) ($services['paymentMode'] ?? 'disabled');

        return in_array($mode, ['disabled', 'sandbox', 'live', 'simulation'], true)
            ? $mode
            : 'disabled';
    }

    public function assertPermitted(string $mode): void
    {
        if ($mode === 'disabled' || ! in_array($mode, ['live', 'sandbox', 'simulation'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['بوابة الدفع معطلة أو وضعها غير صحيح.'],
            ]);
        }

        if ($mode === 'live' && ! app()->environment('production')) {
            throw ValidationException::withMessages([
                'payment' => ['الدفع الحقيقي مسموح في خادم الإنتاج فقط.'],
            ]);
        }

        if (in_array($mode, ['sandbox', 'simulation'], true)) {
            if (app()->environment('production')) {
                throw ValidationException::withMessages([
                    'payment' => ['الدفع التجريبي والمحاكاة ممنوعان في الإنتاج.'],
                ]);
            }

            if (! (bool) config('moyasar.non_live_isolated', false)) {
                throw ValidationException::withMessages([
                    'payment' => ['اختبار الدفع يتطلب قاعدة بيانات غير إنتاجية منفصلة وتفعيل إعداد العزل.'],
                ]);
            }
        }

        if ($mode === 'simulation' && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'payment' => ['المحاكاة الداخلية متاحة محليًا فقط.'],
            ]);
        }
    }

    public function gateway(string $mode): string
    {
        return match ($mode) {
            'live' => 'moyasar_live',
            'sandbox' => 'moyasar_sandbox',
            'simulation' => 'local_test',
            default => throw ValidationException::withMessages([
                'payment' => ['وضع دفع غير صالح.'],
            ]),
        };
    }

    public function modeForGateway(string $gateway): ?string
    {
        return match ($gateway) {
            'moyasar_live' => 'live',
            'moyasar_sandbox' => 'sandbox',
            'local_test' => 'simulation',
            default => null,
        };
    }

    public function credentials(string $mode): array
    {
        $this->assertPermitted($mode);

        if (! in_array($mode, ['live', 'sandbox'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['هذا الوضع لا يستخدم مفاتيح ميسر.'],
            ]);
        }

        $kind = $mode === 'sandbox' ? 'test' : 'live';
        $publishable = trim((string) config("moyasar.{$kind}_publishable_key", ''));
        $secret = trim((string) config("moyasar.{$kind}_secret_key", ''));

        if (
            ! str_starts_with($publishable, "pk_{$kind}_") ||
            ! str_starts_with($secret, "sk_{$kind}_")
        ) {
            throw ValidationException::withMessages([
                'payment' => ['مفاتيح ميسر لهذا الوضع غير مكتملة أو لا تطابق البيئة.'],
            ]);
        }

        return [
            'mode' => $mode,
            'publishable_key' => $publishable,
            'secret_key' => $secret,
        ];
    }

    public function availableForCheckout(): bool
    {
        $mode = $this->currentMode();

        try {
            if ($mode === 'simulation') {
                $this->assertPermitted($mode);
                return true;
            }

            if (in_array($mode, ['sandbox', 'live'], true)) {
                $this->credentials($mode);
                return true;
            }
        } catch (ValidationException) {
            // Public bootstrap fails closed. Never disclose configuration details.
        }

        return false;
    }
}
