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
            'ratings_enabled' => $this->enabled('stores', 'ratingsEnabled', true),
            'allow_temporary_closure' => $this->enabled('stores', 'allowTemporaryClosure', true),
            'automatic_store_activation' => $this->enabled('stores', 'automaticStoreActivation', false),
            'priority_ranking_enabled' => $this->enabled('stores', 'priorityRankingEnabled', true),
            'default_preparation_minutes' => (int) $this->number('stores', 'defaultPreparationMinutes', 30),
            'product_approval_required' => $this->enabled('products_categories', 'productApprovalRequired', true),
            'inventory_tracking_enabled' => $this->enabled('products_categories', 'inventoryTrackingEnabled', true),
            'hide_out_of_stock_products' => $this->enabled('products_categories', 'hideOutOfStockProducts', true),
            'product_image_required' => $this->enabled('products_categories', 'productImageRequired', true),
            'allow_price_changes' => $this->enabled('products_categories', 'allowPriceChanges', true),
            'maximum_products_per_store' => (int) $this->number('products_categories', 'maximumProductsPerStore', 500),
            'maximum_categories_per_store' => (int) $this->number('products_categories', 'maximumCategoriesPerStore', 50),
            'automatic_approval_enabled' => $this->enabled('productive_families', 'automaticApprovalEnabled', false),
            'health_certificate_required' => $this->enabled('productive_families', 'healthCertificateRequired', true),
            'identity_verification_required' => $this->enabled('productive_families', 'identityVerificationRequired', true),
            'auto_create_store' => $this->enabled('productive_families', 'autoCreateStore', true),
            'suspend_expired_certificates' => $this->enabled('productive_families', 'suspendExpiredCertificates', true),
            'maximum_stores_per_family' => (int) $this->number('productive_families', 'maximumStoresPerFamily', 1),
            'approval_sla_hours' => (int) $this->number('productive_families', 'approvalSlaHours', 48),
            'default_language' => (string) ((PlatformControl::cachedValues()['platform']['defaultLanguage'] ?? 'ar')),
            'human_resources_enabled' => $this->enabled('modules', 'humanResourcesEnabled', true),
            'digital_employees_enabled' => $this->enabled('modules', 'digitalEmployeesEnabled', true),
            'governance_enabled' => $this->enabled('modules', 'governanceEnabled', true),
            'two_factor_for_owner' => $this->enabled('security', 'twoFactorForOwner', true),
            'two_factor_for_admins' => $this->enabled('security', 'twoFactorForAdmins', false),
            'allow_multiple_sessions' => $this->enabled('security', 'allowMultipleSessions', false),
            'session_timeout_minutes' => (int) $this->number('security', 'sessionTimeoutMinutes', 120),
            'account_lock_minutes' => (int) $this->number('security', 'accountLockMinutes', 30),
            'require_sensitive_action_confirmation' => $this->enabled('security', 'requireSensitiveActionConfirmation', true),
            'ai_require_owner_approval' => $this->enabled('artificial_intelligence', 'requireOwnerApproval', true),
            'ai_record_all_decisions' => $this->enabled('artificial_intelligence', 'recordAllDecisions', true),
            'ai_confidence_threshold' => (int) $this->number('artificial_intelligence', 'confidenceThreshold', 85),
            'ai_daily_task_limit' => (int) $this->number('artificial_intelligence', 'dailyTaskLimit', 1000),
            'ai_monthly_budget' => $this->number('artificial_intelligence', 'monthlyBudget', 0),
            'ai_max_automatic_action_value' => $this->number('artificial_intelligence', 'maximumAutomaticActionValue', 500),
            'mail_enabled' => $this->enabled('services', 'mailEnabled', true),
            'sms_enabled' => $this->enabled('services', 'smsEnabled', false),
            'firebase_enabled' => $this->enabled('services', 'firebaseEnabled', false),
            'maps_enabled' => $this->enabled('services', 'mapsEnabled', true),
            'cloud_storage_enabled' => $this->enabled('services', 'cloudStorageEnabled', false),
            'queue_enabled' => $this->enabled('operations', 'queueEnabled', true),
            'scheduler_enabled' => $this->enabled('operations', 'schedulerEnabled', true),
            'cache_enabled' => $this->enabled('operations', 'cacheEnabled', true),
            'automatic_backup_enabled' => $this->enabled('operations', 'automaticBackupEnabled', false),
            'backup_retention_days' => (int) $this->number('operations', 'backupRetentionDays', 30),
            'log_retention_days' => (int) $this->number('operations', 'logRetentionDays', 365),
            'audit_logs_enabled' => $this->enabled('governance', 'auditLogsEnabled', true),
            'prevent_audit_log_deletion' => $this->enabled('governance', 'preventAuditLogDeletion', true),
            'four_eyes_principle_enabled' => $this->enabled('governance', 'fourEyesPrincipleEnabled', true),
            'require_reason_for_sensitive_changes' => $this->enabled('governance', 'requireReasonForSensitiveChanges', true),
            'require_owner_approval_for_deletion' => $this->enabled('governance', 'requireOwnerApprovalForDeletion', true),
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