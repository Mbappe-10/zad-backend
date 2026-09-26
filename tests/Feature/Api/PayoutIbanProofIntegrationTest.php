<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\Payout;
use App\Models\ProductiveFamily;
use App\Models\RolePortalRecord;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SecurePayoutProofService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PayoutIbanProofIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_can_sign_contract_upload_pdf_iban_proof_and_submit_payout_for_admin_review(): void
    {
        $user = User::factory()->create();

        $family = ProductiveFamily::query()->create([
            'code' => 'FAM-PAYOUT-001',
            'owner_name' => 'Test Family Owner',
            'phone' => '0500000001',
        ]);

        Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'متجر الأسرة التجريبي',
            'slug' => 'family-payout-test-store',
        ]);

        AppProfile::query()->create([
            'user_id' => $user->id,
            'productive_family_id' => $family->id,
            'active_mode' => 'family',
        ]);

        $wallet = Wallet::query()->create([
            'owner_type' => ProductiveFamily::class,
            'owner_id' => $family->id,
            'currency' => 'SAR',
            'available_balance' => 500,
            'pending_balance' => 0,
            'is_frozen' => false,
            'status' => 'active',
        ]);

        $contract = RolePortalRecord::query()->create([
            'reference' => 'TEST-FAMILY-CONTRACT-V1',
            'role' => 'family',
            'module' => 'contract',
            'title' => 'عقد الأسرة المنتجة التجريبي',
            'status' => 'published',
            'version' => 1,
            'content' => 'Test contract content',
            'effective_at' => now()->subMinute(),
        ]);

        $proofService = Mockery::mock(SecurePayoutProofService::class);

        $proofService->shouldReceive('upload')
            ->once()
            ->andReturn([
                'public_id' => 'secure/test-family-iban-proof',
                'asset_id' => 'asset-test-family-001',
                'resource_type' => 'raw',
                'delivery_type' => 'authenticated',
                'format' => 'pdf',
                'original_name' => 'family-iban.pdf',
                'mime_type' => 'application/pdf',
                'size' => 128,
                'sha256' => hash('sha256', 'TEST-PDF-CONTENT'),
            ]);


        $proofService->shouldReceive('temporaryDownloadUrl')
            ->once()
            ->andReturn('https://example.test/secure/family-iban.pdf');
        $this->app->instance(SecurePayoutProofService::class, $proofService);

        // Forget the controller instance resolved by the contract request so Laravel rebuilds it with the mocked proof service.
        $this->app->forgetInstance(\App\Http\Controllers\Api\App\RolePortalController::class);


        Sanctum::actingAs($user);

        $contractResponse = $this->postJson(
            "/api/v1/app/family/portal/contract",
            [
                'contract_id' => $contract->id,
                'accepted' => true,
                'signature_base64' => 'data:image/png;base64,TEST_SIGNATURE',
            ],
        );

        $contractResponse->assertOk();

        $this->assertDatabaseHas('role_portal_records', [
            'role' => 'family',
            'module' => 'contract-acceptance',
            'owner_type' => ProductiveFamily::class,
            'owner_id' => $family->id,
            'version' => 1,
            'status' => 'accepted',
        ]);

        $pdf = UploadedFile::fake()->create(
            'family-iban.pdf',
            128,
            'application/pdf',
        );

        $payoutResponse = $this->post(
            "/api/v1/app/family/portal/wallet",
            [
                'amount' => 100,
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA0380000000608010167519',
                'account_name' => 'Test Family Owner',
                'declaration_accepted' => '1',
                'signature_base64' => 'data:image/png;base64,TEST_PAYOUT_SIGNATURE',
                'iban_proof' => $pdf,
                'notes' => 'Integration test payout',
            ],
            [
                'Accept' => 'application/json',
            ],
        );

        $payoutResponse->assertCreated();

        $payout = Payout::query()
            ->where('wallet_id', $wallet->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('pending', $payout->status);
        $this->assertSame('family', $payout->account_role);
        $this->assertSame('family-iban.pdf', $payout->iban_proof_original_name);
        $this->assertSame('application/pdf', $payout->iban_proof_mime_type);
        $this->assertTrue((bool) $payout->iban_proof_required);
        $this->assertNotNull($payout->iban_proof_uploaded_at);
        $this->assertNotEmpty($payout->iban_proof_sha256);
        $this->assertNotEmpty($payout->declaration_reference);
        $this->assertNotEmpty($payout->declaration_signature);
        $this->assertNotEmpty($payout->declaration_hash);
        $this->assertNotEmpty($payout->contract_acceptance_reference);

        $wallet->refresh();

        $this->assertSame('400.00', $wallet->available_balance);
        $this->assertSame('100.00', $wallet->pending_balance);

        // Finance administration can inspect the submitted IBAN proof before approval.
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $adminShowResponse = $this->getJson(
            "/api/admin/payout-requests/{$payout->id}",
        );

        $adminShowResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.iban_proof.available', true)
            ->assertJsonPath('data.iban_proof.file_name', 'family-iban.pdf')
            ->assertJsonPath('data.iban_proof.mime_type', 'application/pdf')
            ->assertJsonPath(
                'data.iban_proof.sha256',
                hash('sha256', 'TEST-PDF-CONTENT'),
            );

        // Finance administration opens the signed evidence URL returned by the API.
        $signedDownloadUrl = $adminShowResponse->json('data.iban_proof.download_url');

        $this->assertNotEmpty($signedDownloadUrl);
        $this->assertStringContainsString('signature=', $signedDownloadUrl);

        $signedPath = parse_url($signedDownloadUrl, PHP_URL_PATH);
        $signedQuery = parse_url($signedDownloadUrl, PHP_URL_QUERY);

        $downloadResponse = $this->get(
            $signedPath.($signedQuery ? '?'.$signedQuery : ''),
        );

        $downloadResponse
            ->assertRedirect('https://example.test/secure/family-iban.pdf');
        $approveResponse = $this->patchJson(
            "/api/admin/payout-requests/{$payout->id}/decision",
            [
                'decision' => 'approve',
                'reason' => 'IBAN proof and declaration verified',
            ],
        );

        $approveResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $payout->refresh();
        $wallet->refresh();

        $this->assertSame('approved', $payout->status);
        $this->assertSame($admin->id, $payout->approved_by);
        $this->assertNotNull($payout->approved_at);
        $this->assertNull($payout->paid_at);

        // Approval is not payment: funds remain reserved until bank execution.
        $this->assertSame('400.00', $wallet->available_balance);
        $this->assertSame('100.00', $wallet->pending_balance);

        // Return to the family account for the validation test below.
        Sanctum::actingAs($user);

        $missingProofResponse = $this->postJson(
            "/api/v1/app/family/portal/wallet",
            [
                'amount' => 50,
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA0380000000608010167519',
                'account_name' => 'Test Family Owner',
                'declaration_accepted' => true,
                'signature_base64' => 'data:image/png;base64,TEST_SIGNATURE',
            ],
        );

        $missingProofResponse
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iban_proof']);
    }

    public function test_driver_can_upload_image_iban_proof_and_submit_payout_for_admin_review(): void
    {
        $user = User::factory()->create();

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'code' => 'DRV-PAYOUT-001',
            'name' => 'Test Driver',
            'phone' => '0500000002',
            'status' => 'active',
            'application_status' => 'approved',
        ]);

        AppProfile::query()->create([
            'user_id' => $user->id,
            'driver_id' => $driver->id,
            'active_mode' => 'driver',
        ]);

        $wallet = Wallet::query()->create([
            'owner_type' => Driver::class,
            'owner_id' => $driver->id,
            'currency' => 'SAR',
            'available_balance' => 350,
            'pending_balance' => 0,
            'is_frozen' => false,
            'status' => 'active',
        ]);

        $contract = RolePortalRecord::query()->create([
            'reference' => 'TEST-DRIVER-CONTRACT-V1',
            'role' => 'driver',
            'module' => 'contract',
            'title' => 'Driver test contract',
            'status' => 'published',
            'version' => 1,
            'content' => 'Test driver contract content',
            'effective_at' => now()->subMinute(),
        ]);

        $proofService = Mockery::mock(SecurePayoutProofService::class);

        $proofService->shouldReceive('upload')
            ->once()
            ->andReturn([
                'public_id' => 'secure/test-driver-iban-proof',
                'asset_id' => 'asset-test-driver-001',
                'resource_type' => 'image',
                'delivery_type' => 'authenticated',
                'format' => 'png',
                'original_name' => 'driver-iban.png',
                'mime_type' => 'image/png',
                'size' => 128,
                'sha256' => hash('sha256', 'TEST-DRIVER-IMAGE'),
            ]);

        $proofService->shouldReceive('temporaryDownloadUrl')
            ->once()
            ->andReturn('https://example.test/secure/driver-iban.png');

        $this->app->instance(SecurePayoutProofService::class, $proofService);

        Sanctum::actingAs($user);

        $contractResponse = $this->postJson(
            "/api/v1/app/driver/portal/contract",
            [
                'contract_id' => $contract->id,
                'accepted' => true,
                'signature_base64' => 'data:image/png;base64,DRIVER_SIGNATURE',
            ],
        );

        $contractResponse->assertOk();

        $this->assertDatabaseHas('role_portal_records', [
            'role' => 'driver',
            'module' => 'contract-acceptance',
            'owner_type' => Driver::class,
            'owner_id' => $driver->id,
            'version' => 1,
            'status' => 'accepted',
        ]);

        $image = UploadedFile::fake()->image(
            'driver-iban.png',
            800,
            600,
        );

        $payoutResponse = $this->post(
            "/api/v1/app/driver/portal/wallet",
            [
                'amount' => 100,
                'bank_name' => 'مصرف الراجحي',
                'iban' => 'SA0380000000608010167519',
                'account_name' => 'Test Driver',
                'declaration_accepted' => '1',
                'signature_base64' => 'data:image/png;base64,DRIVER_PAYOUT_SIGNATURE',
                'iban_proof' => $image,
                'notes' => 'Driver integration test payout',
            ],
            [
                'Accept' => 'application/json',
            ],
        );


        $payoutResponse->assertCreated();

        $payout = Payout::query()
            ->where('wallet_id', $wallet->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('pending', $payout->status);
        $this->assertSame('driver', $payout->account_role);
        $this->assertSame('driver-iban.png', $payout->iban_proof_original_name);
        $this->assertSame('image/png', $payout->iban_proof_mime_type);
        $this->assertTrue((bool) $payout->iban_proof_required);
        $this->assertNotNull($payout->iban_proof_uploaded_at);
        $this->assertNotEmpty($payout->iban_proof_sha256);
        $this->assertNotEmpty($payout->declaration_reference);
        $this->assertNotEmpty($payout->declaration_signature);
        $this->assertNotEmpty($payout->declaration_hash);
        $this->assertNotEmpty($payout->contract_acceptance_reference);

        $wallet->refresh();

        $this->assertSame('250.00', $wallet->available_balance);
        $this->assertSame('100.00', $wallet->pending_balance);

        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $adminShowResponse = $this->getJson(
            "/api/admin/payout-requests/{$payout->id}",
        );

        $adminShowResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.iban_proof.available', true)
            ->assertJsonPath('data.iban_proof.file_name', 'driver-iban.png')
            ->assertJsonPath('data.iban_proof.mime_type', 'image/png')
            ->assertJsonPath(
                'data.iban_proof.sha256',
                hash('sha256', 'TEST-DRIVER-IMAGE'),
            );

        $signedDownloadUrl = $adminShowResponse->json(
            'data.iban_proof.download_url',
        );

        $this->assertNotEmpty($signedDownloadUrl);
        $this->assertStringContainsString('signature=', $signedDownloadUrl);

        $signedPath = parse_url($signedDownloadUrl, PHP_URL_PATH);
        $signedQuery = parse_url($signedDownloadUrl, PHP_URL_QUERY);

        $downloadResponse = $this->get(
            $signedPath.($signedQuery ? '?'.$signedQuery : ''),
        );

        $downloadResponse->assertRedirect(
            'https://example.test/secure/driver-iban.png',
        );

        $approveResponse = $this->patchJson(
            "/api/admin/payout-requests/{$payout->id}/decision",
            [
                'decision' => 'approve',
                'reason' => 'Driver IBAN proof verified',
            ],
        );

        $approveResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $payout->refresh();
        $wallet->refresh();

        $this->assertSame('approved', $payout->status);
        $this->assertSame($admin->id, $payout->approved_by);
        $this->assertNotNull($payout->approved_at);
        $this->assertNull($payout->paid_at);

        $this->assertSame('250.00', $wallet->available_balance);
        $this->assertSame('100.00', $wallet->pending_balance);
    }
}