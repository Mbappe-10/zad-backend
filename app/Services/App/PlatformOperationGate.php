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
        $this->assertEnabled(
            'modules',
            'salesEnabled',
            true,
            423,
            'وحدة المبيعات والطلبات متوقفة مؤقتًا.',
        );
        $this->assertEnabled(
            'orders_sales',
            'enabled',
            true,
            423,
            'استقبال الطلبات والمبيعات متوقف مؤقتًا.',
        );
    }

    public function assertGuestOrderAllowed(): void
    {
        abort_unless(
            $this->isGuestCheckoutEnabled(),
            403,
            'طلبات الضيوف متوقفة مؤقتًا. يرجى تسجيل الدخول للمتابعة.',
        );
    }

    public function assertScheduledOrderAllowed(): void
    {
        $this->assertEnabled(
            'orders_sales',
            'scheduledOrdersEnabled',
            true,
            423,
            'الطلبات المجدولة متوقفة مؤقتًا.',
        );
    }

    public function assertMinimumOrderAmount(float $subtotal): void
    {
        $min = $this->number('orders_sales', 'minimumOrderAmount', 0);
        if ($min > 0 && $subtotal < $min) {
            abort(422, "الحد الأدنى للطلب هو {$min} ر.س.");
        }
    }

    public function assertLiveStreamingAllowed(): void
    {
        $this->assertPlatformAvailable();
        $this->assertEnabled(
            'modules',
            'liveStreamingEnabled',
            true,
            423,
            'خدمة البث المباشر متوقفة مؤقتًا.',
        );
    }

    public function assertDeliveryAllowed(): void
    {
        $this->assertPlatformAvailable();
        $this->assertEnabled(
            'modules',
            'deliveryEnabled',
            true,
            423,
            'خدمة التوصيل متوقفة مؤقتًا.',
        );
        $this->assertEnabled(
            'delivery',
            'enabled',
            true,
            423,
            'خدمة التوصيل متوقفة مؤقتًا.',
        );
    }

    public function assertNewPaymentAttemptAllowed(): void
    {
        $this->assertPlatformAvailable();

        $enabled = $this->enabled('modules', 'financeEnabled', true)
            && $this->enabled('services', 'paymentGatewayEnabled', false)
            && $this->enabled('finance', 'paymentsEnabled', true);

        abort_unless(
            $enabled,
            423,
            'إنشاء محاولات دفع جديدة متوقف مؤقتًا.',
        );
    }

    public function isGuestCheckoutEnabled(): bool
    {
        return $this->enabled('platform', 'guestCheckoutEnabled', true)
            && $this->enabled('orders_sales', 'guestOrdersEnabled', true);
    }

    public function isAdvertisementsEnabled(): bool
    {
        return $this->enabled('modules', 'advertisementsEnabled', true);
    }

    public function publicControlsSnapshot(): array
    {
        return [
            'platform_enabled' => $this->enabled('platform', 'platformEnabled', true),
            'maintenance_mode' => $this->enabled('platform', 'maintenanceMode', false),
            'registrations_enabled' => $this->enabled('platform', 'registrationsEnabled', true),
            'new_orders_enabled' => $this->enabled('platform', 'newOrdersEnabled', true)
                && $this->enabled('modules', 'salesEnabled', true)
                && $this->enabled('orders_sales', 'enabled', true),
            'guest_orders_enabled' => $this->isGuestCheckoutEnabled(),
            'scheduled_orders_enabled' => $this->enabled('orders_sales', 'scheduledOrdersEnabled', true),
            'minimum_order_amount' => $this->number('orders_sales', 'minimumOrderAmount', 0),
            'allow_customer_cancellation' => $this->enabled('orders_sales', 'allowCustomerCancellation', true),
            'cancellation_window_minutes' => (int) $this->number('orders_sales', 'cancellationWindowMinutes', 5),
            'allow_refund_requests' => $this->enabled('orders_sales', 'allowRefundRequests', true),
            'require_cancellation_reason' => $this->enabled('orders_sales', 'requireCancellationReason', true),
            'show_distance_to_customer' => $this->enabled('delivery', 'showDistanceToCustomer', false),
            'courier_acceptance_seconds' => (int) $this->number('delivery', 'courierAcceptanceSeconds', 20),
            'smart_dispatch_enabled' => $this->enabled('delivery', 'smartDispatchEnabled', true),
            'automatic_redispatch_enabled' => $this->enabled('delivery', 'automaticRedispatchEnabled', true),
            'advertisements_enabled' => $this->isAdvertisementsEnabled(),
            'live_streaming_enabled' => $this->enabled('modules', 'liveStreamingEnabled', true),
            'delivery_enabled' => $this->enabled('modules', 'deliveryEnabled', true)
                && $this->enabled('delivery', 'enabled', true),
        ];
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

    public function enabled(
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

    public function number(
        string $section,
        string $key,
        float $default,
    ): float {
        $sections = PlatformControl::cachedValues();
        $values = $sections[$section] ?? [];

        if (! array_key_exists($key, $values) || ! is_numeric($values[$key])) {
            return $default;
        }

        return (float) $values[$key];
    }
}