<?php

namespace App\Services\App;

use App\Models\AppSetting;
use App\Models\PlatformControl;

class PlatformOperationGate
{
    public function assertNewAccountRegistrationAllowed(): void
    {
        $this->assertPlatformAvailable();
        $this->assertEnabled(
            'platform',
            'registrationsEnabled',
            true,
            403,
            'التسجيل متوقف مؤقتًا.',
        );
    }

    public function assertNewFamilyRegistrationAllowed(): void
    {
        $this->assertNewAccountRegistrationAllowed();
        $this->assertEnabled(
            'productive_families',
            'registrationsEnabled',
            true,
            403,
            'تسجيل الأسر المنتجة متوقف مؤقتًا.',
        );

        abort_unless(
            AppSetting::isEnabled('registration.family_enabled'),
            403,
            'تسجيل الأسر المنتجة متوقف مؤقتًا.',
        );
    }

    public function assertNewDriverRegistrationAllowed(): void
    {
        $this->assertNewAccountRegistrationAllowed();

        abort_unless(
            AppSetting::isEnabled('registration.driver_enabled'),
            403,
            'تسجيل المندوبين متوقف مؤقتًا.',
        );
    }

    public function assertNewOrderAllowed(): void
    {
        $this->assertPlatformAvailable();
        $this->assertEnabled(
            'platform',
            'newOrdersEnabled',
            true,
            423,
            'استقبال الطلبات الجديدة متوقف مؤقتًا.',
        );
    }

    public function assertNewPaymentAttemptAllowed(): void
    {
        $this->assertPlatformAvailable();

        $enabled = $this->enabled('modules', 'financeEnabled', true)
            && $this->enabled(
                'services',
                'paymentGatewayEnabled',
                false,
            )
            && $this->enabled('finance', 'paymentsEnabled', true);

        abort_unless(
            $enabled,
            423,
            'إنشاء محاولات دفع جديدة متوقف مؤقتًا.',
        );
    }

    private function assertPlatformAvailable(): void
    {
        $this->assertEnabled(
            'platform',
            'platformEnabled',
            true,
            503,
            'المنصة غير متاحة مؤقتًا.',
        );

        abort_if(
            $this->enabled('platform', 'maintenanceMode', false),
            503,
            'المنصة تحت الصيانة مؤقتًا.',
        );
    }

    private function assertEnabled(
        string $section,
        string $key,
        bool $default,
        int $status,
        string $message,
    ): void {
        abort_unless(
            $this->enabled($section, $key, $default),
            $status,
            $message,
        );
    }

    private function enabled(
        string $section,
        string $key,
        bool $default,
    ): bool {
        $sections = PlatformControl::cachedValues();
        $values = $sections[$section] ?? [];

        if (! array_key_exists($key, $values)) {
            return $default;
        }

        return filter_var(
            $values[$key],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
    }
}
