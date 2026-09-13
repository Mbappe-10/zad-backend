<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\AppProfile;
use App\Models\City;
use App\Models\Driver;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\App\SocialAccountLinker;
use App\Support\InternalTesting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InternalTestingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_orphan_google_user_merges_into_email_account(): void
    {
        $existing = User::query()->create([
            'name' => 'Existing Driver',
            'email' => 'driver@example.test',
            'status' => 'active',
            'is_approved' => true,
        ]);

        $orphan = User::query()->create([
            'name' => 'Google Orphan',
            'auth_provider' => 'google',
            'provider_user_id' => 'same-google-subject',
            'status' => 'active',
            'is_approved' => true,
        ]);

        AppProfile::query()->create([
            'user_id' => $orphan->id,
            'roles' => ['driver'],
            'active_mode' => 'driver',
        ]);

        [$resolved] = DB::transaction(
            fn (): array => app(SocialAccountLinker::class)
                ->resolveGoogleUser(
                    'same-google-subject',
                    'driver@example.test',
                    'Existing Driver',
                    'driver',
                ),
        );

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(
            'same-google-subject',
            $resolved->provider_user_id,
        );
        $this->assertTrue(
            User::withTrashed()->findOrFail($orphan->id)->trashed(),
        );
    }

    public function test_family_email_links_to_the_existing_catalog_store(): void
    {
        config()->set('internal_testing.enabled', true);
        config()->set(
            'internal_testing.family.email',
            'family@example.test',
        );
        config()->set(
            'internal_testing.family.store_slug',
            'existing-family-store',
        );

        $family = ProductiveFamily::query()->create([
            'code' => 'FAMILY-TEST-1',
            'owner_name' => 'أسرة اختبار',
            'phone' => 'demo-family-test-1',
            'status' => 'active',
        ]);

        Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'متجر الأسرة',
            'slug' => 'existing-family-store',
            'status' => 'active',
            'is_open' => true,
            'rating' => 0,
            'rating_count' => 0,
            'pickup_address' => 'Test pickup',
            'pickup_latitude' => 21.4225,
            'pickup_longitude' => 39.8262,
        ]);

        [$user, $profile] = DB::transaction(function (): array {
            $linker = app(SocialAccountLinker::class);

            [$user] = $linker->resolveGoogleUser(
                'google-family-subject',
                'family@example.test',
                'Family Owner',
                'productive_family',
            );

            $profile = AppProfile::query()->create([
                'user_id' => $user->id,
                'roles' => ['productive_family'],
                'active_mode' => 'productive_family',
            ]);

            $this->assertTrue($linker->linkRoleAccount(
                $user,
                $profile,
                'family@example.test',
                'productive_family',
                'Family Owner',
            ));

            return [$user, $profile->fresh()];
        });

        $this->assertSame($family->id, $profile->productive_family_id);
        $this->assertSame('family@example.test', $user->email);
    }

    public function test_operational_family_user_wins_over_an_orphan_google_user(): void
    {
        config()->set('internal_testing.enabled', true);
        config()->set(
            'internal_testing.family.email',
            'family-owner@example.test',
        );
        config()->set(
            'internal_testing.family.store_slug',
            'operational-family-store',
        );

        $operationalUser = User::query()->create([
            'name' => 'Operational Family',
            'status' => 'active',
            'is_approved' => true,
        ]);

        $family = ProductiveFamily::query()->create([
            'code' => 'FAMILY-TEST-2',
            'owner_name' => 'أسرة تشغيلية',
            'phone' => 'demo-family-test-2',
            'status' => 'active',
        ]);

        Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'المتجر التشغيلي',
            'slug' => 'operational-family-store',
            'status' => 'active',
            'is_open' => true,
            'rating' => 0,
            'rating_count' => 0,
            'pickup_address' => 'Test pickup',
            'pickup_latitude' => 21.4225,
            'pickup_longitude' => 39.8262,
        ]);

        AppProfile::query()->create([
            'user_id' => $operationalUser->id,
            'productive_family_id' => $family->id,
            'roles' => ['productive_family'],
            'active_mode' => 'productive_family',
        ]);

        $orphan = User::query()->create([
            'name' => 'Old Google Attempt',
            'email' => 'family-owner@example.test',
            'auth_provider' => 'google',
            'provider_user_id' => 'family-google-subject',
            'status' => 'active',
            'is_approved' => true,
        ]);

        AppProfile::query()->create([
            'user_id' => $orphan->id,
            'roles' => ['productive_family'],
            'active_mode' => 'productive_family',
        ]);

        [$resolved] = DB::transaction(
            fn (): array => app(SocialAccountLinker::class)
                ->resolveGoogleUser(
                    'family-google-subject',
                    'family-owner@example.test',
                    'Family Owner',
                    'productive_family',
                ),
        );

        $this->assertSame($operationalUser->id, $resolved->id);
        $this->assertSame(
            'family-owner@example.test',
            $resolved->email,
        );
        $this->assertSame(
            'family-google-subject',
            $resolved->provider_user_id,
        );
        $this->assertTrue(
            User::withTrashed()->findOrFail($orphan->id)->trashed(),
        );
    }

    public function test_configured_driver_is_created_and_linked_as_approved(): void
    {
        config()->set('internal_testing.enabled', true);
        config()->set(
            'internal_testing.driver.email',
            'driver@example.test',
        );
        config()->set('internal_testing.driver.code', 'DRV-TEST-1');
        config()->set('internal_testing.driver.name', 'مندوب اختبار');
        config()->set(
            'internal_testing.driver.phone',
            '0500000002',
        );
        config()->set(
            'internal_testing.driver.city_code',
            'makkah',
        );
        config()->set(
            'internal_testing.driver.vehicle_type',
            'car',
        );
        config()->set(
            'internal_testing.driver.create_if_missing',
            true,
        );

        City::query()->create([
            'code' => 'makkah',
            'name_ar' => 'مكة المكرمة',
            'name_en' => 'Makkah',
            'is_active' => true,
        ]);

        [$user, $profile] = DB::transaction(function (): array {
            $linker = app(SocialAccountLinker::class);

            [$user] = $linker->resolveGoogleUser(
                'google-driver-subject',
                'driver@example.test',
                'Driver',
                'driver',
            );

            $profile = AppProfile::query()->create([
                'user_id' => $user->id,
                'roles' => ['driver'],
                'active_mode' => 'driver',
            ]);

            $this->assertTrue($linker->linkRoleAccount(
                $user,
                $profile,
                'driver@example.test',
                'driver',
                'Driver',
            ));

            return [$user, $profile->fresh()];
        });

        $driver = Driver::query()->findOrFail($profile->driver_id);

        $this->assertSame($user->id, $driver->user_id);
        $this->assertSame('active', $driver->status);
        $this->assertSame(
            Driver::APPLICATION_APPROVED,
            $driver->application_status,
        );
    }

    public function test_checkout_otp_is_generated_and_verified_in_testing(): void
    {
        config()->set(
            'internal_testing.otp.expires_minutes',
            3,
        );

        $guest = AppGuestSession::query()->create([
            'device_id' => 'test-device',
            'last_seen_at' => now(),
        ]);

        $send = $this->postJson(
            '/api/v1/app/phone-verifications/send',
            [
                'phone' => '0500000003',
                'purpose' => 'checkout',
                'guest_session_id' => $guest->id,
            ],
        );

        $send
            ->assertCreated()
            ->assertJsonPath('test_mode', true)
            ->assertJsonPath('expires_in_seconds', 180);

        $code = (string) $send->json('development_code');

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);

        $this->postJson(
            '/api/v1/app/phone-verifications/verify',
            [
                'verification_id' => $send->json('verification_id'),
                'code' => $code,
                'guest_session_id' => $guest->id,
            ],
        )
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonStructure(['verification_token']);
    }

    public function test_simulated_payment_is_limited_to_allowlisted_phone(): void
    {
        config()->set('internal_testing.enabled', true);
        config()->set(
            'internal_testing.payment.simulate_success',
            true,
        );
        config()->set(
            'internal_testing.otp.allowed_phones',
            ['+966500000004'],
        );

        $this->assertTrue(
            InternalTesting::simulatesPaymentFor('0500000004'),
        );
        $this->assertFalse(
            InternalTesting::simulatesPaymentFor('0500000005'),
        );
    }
}
