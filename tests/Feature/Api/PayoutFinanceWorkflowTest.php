<?php

namespace Tests\Feature\Api;

use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayoutFinanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_does_not_pay_and_second_employee_executes_once(): void
    {
        $owner = User::factory()->create();
        $approver = User::factory()->create();
        $executor = User::factory()->create();

        $wallet = Wallet::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'currency' => 'SAR',
            'available_balance' => 100,
            'pending_balance' => 50,
            'is_frozen' => false,
            'status' => 'active',
        ]);

        $payout = Payout::query()->create([
            'wallet_id' => $wallet->id,
            'reference' => 'TEST-PAYOUT-001',
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'status' => 'pending',
            'bank_name' => 'Test Bank',
            'iban' => 'SA0000000000000000000000',
            'account_name' => 'Test Owner',
            'requested_by' => $owner->id,
        ]);

        $service = app(FinancialService::class);

        $approved = $service->decidePayout(
            $payout,
            'approve',
            $approver->id,
            'Verified',
        );

        $this->assertSame('approved', $approved->status);
        $this->assertSame($approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertNull($approved->paid_at);

        $wallet->refresh();
        $this->assertSame('100.00', $wallet->available_balance);
        $this->assertSame('50.00', $wallet->pending_balance);

        try {
            $service->startPayoutProcessing($approved, $approver->id);
            $this->fail('Approver must not execute the same payout.');
        } catch (ValidationException $exception) {
            $this->assertTrue(true);
        }

        $processing = $service->startPayoutProcessing(
            $approved->fresh(),
            $executor->id,
        );

        $this->assertSame('processing', $processing->status);
        $this->assertSame($executor->id, $processing->processing_by);
        $this->assertNotNull($processing->processing_at);

        $paid = $service->completePayout(
            $processing,
            'BANK-REF-001',
            $executor->id,
            'Transferred',
        );

        $this->assertSame('paid', $paid->status);
        $this->assertSame($executor->id, $paid->executed_by);
        $this->assertSame('BANK-REF-001', $paid->bank_transfer_reference);
        $this->assertNotNull($paid->executed_at);
        $this->assertNotNull($paid->paid_at);

        $wallet->refresh();
        $this->assertSame('100.00', $wallet->available_balance);
        $this->assertSame('0.00', $wallet->pending_balance);

        $this->expectException(ValidationException::class);

        $service->completePayout(
            $paid->fresh(),
            'BANK-REF-002',
            $executor->id,
        );
    }

    public function test_rejection_returns_reserved_amount_to_available_balance(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();

        $wallet = Wallet::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'currency' => 'SAR',
            'available_balance' => 100,
            'pending_balance' => 40,
            'is_frozen' => false,
            'status' => 'active',
        ]);

        $payout = Payout::query()->create([
            'wallet_id' => $wallet->id,
            'reference' => 'TEST-PAYOUT-002',
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'status' => 'pending',
            'bank_name' => 'Test Bank',
            'iban' => 'SA0000000000000000000000',
            'account_name' => 'Test Owner',
            'requested_by' => $owner->id,
        ]);

        $rejected = app(FinancialService::class)->decidePayout(
            $payout,
            'reject',
            $reviewer->id,
            'IBAN mismatch',
        );

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame($reviewer->id, $rejected->rejected_by);
        $this->assertSame('IBAN mismatch', $rejected->rejection_reason);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertNull($rejected->paid_at);

        $wallet->refresh();
        $this->assertSame('140.00', $wallet->available_balance);
        $this->assertSame('0.00', $wallet->pending_balance);
    }
}