<?php

namespace Tests\Feature\Api;

use App\Models\PlatformControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ControlCenterSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'is_platform_owner' => true,
        ])->save();

        return $user->fresh();
    }

    public function test_owner_can_save_and_reload_every_control_center_section(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/control-center')->assertOk();

        $sections = [
            'platform' => [
                'platformEnabled' => true,
                'maintenanceMode' => false,
                'registrationsEnabled' => true,
                'newOrdersEnabled' => true,
            ],

            'modules' => [
                'salesEnabled' => true,
                'deliveryEnabled' => true,
                'financeEnabled' => true,
                'humanResourcesEnabled' => true,
                'digitalEmployeesEnabled' => true,
                'advertisementsEnabled' => true,
                'liveStreamingEnabled' => true,
                'governanceEnabled' => true,
            ],

            'security' => [
                'twoFactorForOwner' => true,
                'twoFactorForAdmins' => false,
                'sessionTimeoutMinutes' => 120,
                'maximumLoginAttempts' => 5,
                'accountLockMinutes' => 30,
                'allowMultipleSessions' => false,
                'requireSensitiveActionConfirmation' => true,
            ],

            'artificial_intelligence' => [
                'enabled' => true,
                'requireOwnerApproval' => true,
                'confidenceThreshold' => 85,
                'dailyTaskLimit' => 1000,
                'monthlyBudget' => 0,
                'maximumAutomaticActionValue' => 500,
                'recordAllDecisions' => true,
            ],

            'services' => [
                'mailEnabled' => true,
                'smsEnabled' => false,
                'firebaseEnabled' => false,
                'mapsEnabled' => true,
                'paymentGatewayEnabled' => false,
                'cloudStorageEnabled' => false,
            ],

            'operations' => [
                'queueEnabled' => true,
                'schedulerEnabled' => true,
                'cacheEnabled' => true,
                'automaticBackupEnabled' => false,
                'backupRetentionDays' => 30,
                'logRetentionDays' => 365,
            ],

            'orders_sales' => [
                'enabled' => true,
                'guestOrdersEnabled' => true,
                'scheduledOrdersEnabled' => true,
                'allowCustomerCancellation' => true,
                'allowRefundRequests' => true,
                'requireCancellationReason' => true,
                'minimumOrderAmount' => 0,
                'cancellationWindowMinutes' => 5,
                'automaticOrderExpiryMinutes' => 15,
            ],

            'productive_families' => [
                'registrationsEnabled' => true,
                'automaticApprovalEnabled' => false,
                'healthCertificateRequired' => true,
                'identityVerificationRequired' => true,
                'autoCreateStore' => true,
                'suspendExpiredCertificates' => true,
                'maximumStoresPerFamily' => 1,
                'approvalSlaHours' => 48,
            ],

            'stores' => [
                'enabled' => true,
                'automaticStoreActivation' => false,
                'allowTemporaryClosure' => true,
                'ratingsEnabled' => true,
                'showOnlyOpenStores' => false,
                'priorityRankingEnabled' => true,
                'minimumRatingForVisibility' => 0,
                'defaultPreparationMinutes' => 30,
            ],

            'products_categories' => [
                'productsEnabled' => true,
                'categoriesEnabled' => true,
                'productApprovalRequired' => true,
                'inventoryTrackingEnabled' => true,
                'hideOutOfStockProducts' => true,
                'productImageRequired' => true,
                'allowPriceChanges' => true,
                'maximumProductsPerStore' => 500,
                'maximumCategoriesPerStore' => 50,
            ],

            'delivery' => [
                'enabled' => true,
                'scooterEnabled' => true,
                'motorcycleEnabled' => true,
                'carEnabled' => true,
                'smartDispatchEnabled' => true,
                'automaticRedispatchEnabled' => true,
                'showDistanceToCustomer' => false,
                'courierAcceptanceSeconds' => 20,
                'scooterMaximumDistanceKm' => 10,
                'motorcycleMaximumDistanceKm' => 15,
                'baseDeliveryFee' => 5,
                'pricePerKilometer' => 1,
            ],

            'finance' => [
                'walletsEnabled' => true,
                'paymentsEnabled' => true,
                'commissionsEnabled' => true,
                'automaticPayoutsEnabled' => false,
                'ownerApprovalRequired' => true,
                'allowWalletFreeze' => true,
                'platformCommissionPercent' => 10,
                'familyMinimumWithdrawalAmount' => 75,
                'courierMinimumWithdrawalAmount' => 75,
                'familyPayoutCycleDays' => 5,
                'courierPayoutCycleDays' => 21,
                'familyPayoutProcessingDays' => 1,
                'courierPayoutProcessingDays' => 3,
                'familyPayoutHoldHours' => 24,
                'courierPayoutHoldHours' => 24,
                'payoutTransferFee' => 0,
            ],

            'app_updates' => [
                'androidLatestVersion' => '1.0.0',
                'androidLatestBuild' => 1,
                'androidMinimumBuild' => 1,
                'androidForceUpdate' => false,
                'androidStoreUrl' => 'https://play.google.com/store/apps/details?id=com.zadsync.app',
                'iosLatestVersion' => '1.0.0',
                'iosLatestBuild' => 1,
                'iosMinimumBuild' => 1,
                'iosForceUpdate' => false,
                'iosStoreUrl' => 'https://apps.apple.com/app/id1234567890',
            ],

            'governance' => [
                'auditLogsEnabled' => true,
                'preventAuditLogDeletion' => true,
                'fourEyesPrincipleEnabled' => true,
                'requireReasonForSensitiveChanges' => true,
                'requireOwnerApprovalForDeletion' => true,
            ],
        ];

        foreach ($sections as $section => $value) {
            $response = $this->patchJson(
                "/api/admin/control-center/{$section}",
                [
                    'value' => $value,
                    'confirmation' => true,
                    'reason' => "Automated audit: {$section}",
                ]
            );

            $response->assertOk();

            $stored = PlatformControl::query()
                ->where('section', $section)
                ->firstOrFail();

            foreach ($value as $key => $expected) {
                $this->assertSame(
                    $expected,
                    $stored->value[$key] ?? null,
                    "{$section}.{$key} was not persisted correctly"
                );
            }
        }

        $reload = $this->getJson('/api/admin/control-center');

        $reload
            ->assertOk()
            ->assertJsonPath('meta.isOwner', true)
            ->assertJsonPath('meta.canManagePlatform', true);
    }

    public function test_owner_platform_action_buttons_toggle_real_flags(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/control-center')->assertOk();

        $actions = [
            'maintenance-on' => ['maintenanceMode', true],
            'maintenance-off' => ['maintenanceMode', false],
            'registrations-off' => ['registrationsEnabled', false],
            'registrations-on' => ['registrationsEnabled', true],
            'orders-off' => ['newOrdersEnabled', false],
            'orders-on' => ['newOrdersEnabled', true],
        ];

        foreach ($actions as $action => [$key, $expected]) {
            $this->postJson(
                "/api/admin/control-center/actions/{$action}",
                [
                    'confirmation' => true,
                    'reason' => "Automated action audit: {$action}",
                ]
            )
                ->assertOk()
                ->assertJsonPath('action', $action)
                ->assertJsonPath('result.key', $key)
                ->assertJsonPath('result.value', $expected);

            $platform = PlatformControl::query()
                ->where('section', 'platform')
                ->firstOrFail();

            $this->assertSame(
                $expected,
                $platform->value[$key] ?? null,
                "{$action} did not change {$key}"
            );
        }

        $history = PlatformControl::query()
            ->where('section', 'action_history')
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(
            6,
            count($history->value['items'] ?? [])
        );
    }
}