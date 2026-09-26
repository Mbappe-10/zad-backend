<?php

namespace App\Services;

use App\Models\FinancialLedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialService
{
    public function credit(Wallet $wallet, float $amount, string $type, string $description, ?object $source, ?int $userId): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $type, $description, $source, $userId) {
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($wallet->is_frozen) {
                throw ValidationException::withMessages(['wallet' => 'ط§ظ„ظ…ط­ظپط¸ط© ظ…ط¬ظ…ط¯ط©.']);
            }
            $wallet->available_balance = round((float) $wallet->available_balance + $amount, 2);
            $wallet->save();
            $tx = WalletTransaction::create(['wallet_id' => $wallet->id, 'reference' => 'WTX-'.Str::upper(Str::random(14)), 'type' => $type, 'amount' => $amount, 'balance_after' => $wallet->available_balance, 'status' => 'completed', 'related_type' => $source?->getMorphClass(), 'related_id' => $source?->getKey(), 'description' => $description, 'created_by' => $userId]);
            $this->ledger('wallet_liability', 'credit', $amount, $description, $source, $userId);

            return $tx;
        });
    }

    public function holdCredit(
        Wallet $wallet,
        float $amount,
        string $reference,
        string $type,
        string $description,
        ?object $source,
        ?int $userId,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['ظ‚ظٹظ…ط© ط§ظ„ط±طµظٹط¯ ط§ظ„ظ…ط¹ظ„ظ‚ ظٹط¬ط¨ ط£ظ† طھظƒظˆظ† ط£ظƒط¨ط± ظ…ظ† طµظپط±.'],
            ]);
        }

        return DB::transaction(function () use (
            $wallet,
            $amount,
            $reference,
            $type,
            $description,
            $source,
            $userId,
        ): WalletTransaction {
            $existing = WalletTransaction::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            $wallet->pending_balance = round(
                (float) $wallet->pending_balance + $amount,
                2,
            );
            $wallet->save();

            return WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'reference' => $reference,
                'type' => $type,
                'amount' => round($amount, 2),
                'balance_after' => (float) $wallet->available_balance,
                'status' => 'pending',
                'related_type' => $source?->getMorphClass(),
                'related_id' => $source?->getKey(),
                'description' => $description,
                'created_by' => $userId,
            ]);
        });
    }

    public function releaseHeldCredit(
        int $transactionId,
        ?int $userId,
    ): WalletTransaction {
        return DB::transaction(function () use ($transactionId, $userId): WalletTransaction {
            $transaction = WalletTransaction::query()
                ->lockForUpdate()
                ->findOrFail($transactionId);

            if ($transaction->status === 'completed') {
                return $transaction;
            }

            if ($transaction->status !== 'pending') {
                throw ValidationException::withMessages([
                    'transaction' => ['ط§ظ„ط­ط±ظƒط© ط§ظ„ظ…ط§ظ„ظٹط© ظ„ظٹط³طھ ظپظٹ ط­ط§ظ„ط© طھط³ظ…ط­ ط¨طھط­ط±ظٹط±ظ‡ط§.'],
                ]);
            }

            $wallet = Wallet::query()
                ->lockForUpdate()
                ->findOrFail($transaction->wallet_id);

            $amount = (float) $transaction->amount;
            $wallet->pending_balance = max(
                round((float) $wallet->pending_balance - $amount, 2),
                0,
            );
            $wallet->available_balance = round(
                (float) $wallet->available_balance + $amount,
                2,
            );
            $wallet->save();

            $transaction->update([
                'status' => 'completed',
                'balance_after' => $wallet->available_balance,
                'created_by' => $transaction->created_by ?? $userId,
            ]);

            $this->ledger(
                'wallet_liability',
                'credit',
                $amount,
                $transaction->description ?? 'طھط­ط±ظٹط± ظ…ط³طھط­ظ‚ط§طھ ط·ظ„ط¨',
                $transaction,
                $userId,
            );

            return $transaction->fresh();
        });
    }

    public function adjustWallet(
        Wallet $wallet,
        string $direction,
        float $amount,
        string $description,
        ?string $reference,
        ?int $userId,
    ): WalletTransaction {
        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw ValidationException::withMessages([
                'direction' => ['ظ†ظˆط¹ طھط¹ط¯ظٹظ„ ط§ظ„ظ…ط­ظپط¸ط© ط؛ظٹط± طµط§ظ„ط­.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['ظٹط¬ط¨ ط£ظ† ظٹظƒظˆظ† ظ…ط¨ظ„ط؛ ط§ظ„طھط¹ط¯ظٹظ„ ط£ظƒط¨ط± ظ…ظ† طµظپط±.'],
            ]);
        }

        return DB::transaction(function () use (
            $wallet,
            $direction,
            $amount,
            $description,
            $reference,
            $userId,
        ): WalletTransaction {
            $wallet = Wallet::query()
                ->lockForUpdate()
                ->findOrFail($wallet->id);

            if (($wallet->status ?? null) === 'closed') {
                throw ValidationException::withMessages([
                    'wallet' => ['ظ„ط§ ظٹظ…ظƒظ† طھط¹ط¯ظٹظ„ ط±طµظٹط¯ ظ…ط­ظپط¸ط© ظ…ط؛ظ„ظ‚ط©.'],
                ]);
            }

            $currentBalance = (float) $wallet->available_balance;

            if ($direction === 'debit' && $amount > $currentBalance) {
                throw ValidationException::withMessages([
                    'amount' => ['ظ‚ظٹظ…ط© ط§ظ„ط®طµظ… طھطھط¬ط§ظˆط² ط§ظ„ط±طµظٹط¯ ط§ظ„ظ…طھط§ط­.'],
                ]);
            }

            $newBalance = $direction === 'credit'
                ? $currentBalance + $amount
                : $currentBalance - $amount;

            $wallet->available_balance = round($newBalance, 2);
            $wallet->save();

            $transaction = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'reference' => $reference ?: 'ADJ-'.Str::upper(Str::random(16)),
                'type' => $direction === 'credit'
                    ? 'manual_credit'
                    : 'manual_debit',
                'amount' => round($amount, 2),
                'balance_after' => (float) $wallet->available_balance,
                'status' => 'completed',
                'description' => $description,
                'created_by' => $userId,
            ]);

            $this->ledger(
                'wallet_liability',
                $direction === 'credit' ? 'credit' : 'debit',
                $amount,
                $description,
                $transaction,
                $userId,
            );

            return $transaction;
        });
    }

    public function requestPayout(Wallet $wallet, array $data, ?int $userId): Payout
    {
        return DB::transaction(function () use ($wallet, $data, $userId) {
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($wallet->is_frozen) {
                throw ValidationException::withMessages(['wallet' => 'ط§ظ„ظ…ط­ظپط¸ط© ظ…ط¬ظ…ط¯ط©.']);
            }
            if (Payout::query()->where('wallet_id', $wallet->id)
                ->whereIn('status', ['pending', 'approved', 'processing'])
                ->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['payout' => 'ظٹظˆط¬ط¯ ط·ظ„ط¨ ط³ط­ط¨ ظ…ظپطھظˆط­ ظ„ظ‡ط°ط§ ط§ظ„ط­ط³ط§ط¨.']);
            }
            $amount = (float) $data['amount'];
            if ($amount <= 0 || $amount > (float) $wallet->available_balance) {
                throw ValidationException::withMessages(['amount' => 'ط§ظ„ط±طµظٹط¯ ط§ظ„ظ…طھط§ط­ ط؛ظٹط± ظƒط§ظپظچ.']);
            }
            $wallet->available_balance = round((float) $wallet->available_balance - $amount, 2);
            $wallet->pending_balance = round((float) $wallet->pending_balance + $amount, 2);
            $wallet->save();

            return Payout::create([...$data, 'wallet_id' => $wallet->id, 'reference' => 'PAY-'.Str::upper(Str::random(14)), 'fee' => $data['fee'] ?? 0, 'net_amount' => round($amount - (float) ($data['fee'] ?? 0), 2), 'status' => 'pending', 'requested_by' => $userId]);
        });
    }

    public function decidePayout(Payout $payout, string $decision, ?int $userId, ?string $notes = null): Payout
    {
        return DB::transaction(function () use ($payout, $decision, $userId, $notes) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($payout->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'تم اتخاذ قرار على طلب الصرف مسبقًا.',
                ]);
            }

            $wallet = Wallet::query()
                ->lockForUpdate()
                ->findOrFail($payout->wallet_id);

            if ($decision === 'reject') {
                $wallet->pending_balance = max(
                    round((float) $wallet->pending_balance - (float) $payout->amount, 2),
                    0
                );
                $wallet->available_balance = round(
                    (float) $wallet->available_balance + (float) $payout->amount,
                    2
                );
                $wallet->save();

                $payout->update([
                    'status' => 'rejected',
                    'rejection_reason' => $notes,
                    'rejected_by' => $userId,
                    'rejected_at' => now(),
                ]);
            } else {
                $payout->update([
                    'status' => 'approved',
                    'approved_by' => $userId,
                    'approved_at' => now(),
                    'approval_notes' => $notes,
                ]);
            }

            return $payout->fresh();
        });
    }

    public function startPayoutProcessing(Payout $payout, ?int $userId): Payout
    {
        return DB::transaction(function () use ($payout, $userId) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($payout->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => 'طلب الصرف ليس جاهزًا للتحويل.',
                ]);
            }

            if ($payout->approved_by !== null && $userId !== null
                && (int) $payout->approved_by === (int) $userId) {
                throw ValidationException::withMessages([
                    'user' => 'يجب أن ينفذ التحويل موظف مختلف عن موظف الاعتماد.',
                ]);
            }

            $payout->update([
                'status' => 'processing',
                'processing_by' => $userId,
                'processing_at' => now(),
            ]);

            return $payout->fresh();
        });
    }

    public function completePayout(
        Payout $payout,
        string $bankTransferReference,
        ?int $userId,
        ?string $notes = null,
        array $transferProof = []
    ): Payout {
        return DB::transaction(function () use (
            $payout,
            $bankTransferReference,
            $userId,
            $notes,
            $transferProof
        ) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($payout->status !== 'processing') {
                throw ValidationException::withMessages([
                    'status' => 'طلب الصرف ليس قيد التنفيذ.',
                ]);
            }

            if ($payout->processing_by !== null && $userId !== null
                && (int) $payout->processing_by !== (int) $userId) {
                throw ValidationException::withMessages([
                    'user' => 'إكمال التحويل يجب أن يتم بواسطة موظف التنفيذ نفسه.',
                ]);
            }

            if (trim($bankTransferReference) === '') {
                throw ValidationException::withMessages([
                    'bank_transfer_reference' => 'مرجع التحويل البنكي مطلوب.',
                ]);
            }

            $wallet = Wallet::query()
                ->lockForUpdate()
                ->findOrFail($payout->wallet_id);

            if ((float) $wallet->pending_balance < (float) $payout->amount) {
                throw ValidationException::withMessages([
                    'wallet' => 'الرصيد المعلق لا يغطي طلب الصرف.',
                ]);
            }

            $wallet->pending_balance = round(
                (float) $wallet->pending_balance - (float) $payout->amount,
                2
            );
            $wallet->save();

            $payout->update([
                'status' => 'paid',
                'executed_by' => $userId,
                'executed_at' => now(),
                'paid_at' => now(),
                'bank_transfer_reference' => trim($bankTransferReference),
                'transfer_notes' => $notes,
                'transfer_proof_public_id' => $transferProof['public_id'] ?? null,
                'transfer_proof_asset_id' => $transferProof['asset_id'] ?? null,
                'transfer_proof_resource_type' => $transferProof['resource_type'] ?? null,
                'transfer_proof_delivery_type' => $transferProof['delivery_type'] ?? null,
                'transfer_proof_format' => $transferProof['format'] ?? null,
                'transfer_proof_original_name' => $transferProof['original_name'] ?? null,
                'transfer_proof_mime_type' => $transferProof['mime_type'] ?? null,
                'transfer_proof_size' => $transferProof['size'] ?? null,
                'transfer_proof_sha256' => $transferProof['sha256'] ?? null,
                'transfer_proof_uploaded_at' => filled($transferProof) ? now() : null,
            ]);

            $this->ledger(
                'cash',
                'debit',
                (float) $payout->net_amount,
                'صرف مستحقات - تحويل بنكي منفذ',
                $payout,
                $userId
            );

            return $payout->fresh();
        });
    }
    public function refund(Payment $payment, array $data, ?int $userId): Refund
    {
        return DB::transaction(function () use ($payment, $data, $userId) {
            $refunded = (float) Refund::where('payment_id', $payment->id)->whereIn('status', ['approved', 'completed'])->sum('amount');
            $amount = (float) $data['amount'];
            if ($amount <= 0 || $refunded + $amount > (float) $payment->gross_amount) {
                throw ValidationException::withMessages(['amount' => 'ظ‚ظٹظ…ط© ط§ظ„ط§ط³طھط±ط¯ط§ط¯ طھطھط¬ط§ظˆط² ط§ظ„ظ…ط¨ظ„ط؛ ط§ظ„ظ‚ط§ط¨ظ„ ظ„ظ„ط§ط³طھط±ط¯ط§ط¯.']);
            }
            $refund = Refund::create(['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'reference' => 'REF-'.Str::upper(Str::random(14)), 'amount' => $amount, 'status' => 'completed', 'reason' => $data['reason'], 'requested_by' => $userId, 'approved_by' => $userId, 'approved_at' => now(), 'refunded_at' => now()]);
            $this->ledger('customer_refunds', 'debit', $amount, 'ط§ط³طھط±ط¯ط§ط¯ ط¯ظپط¹ط©', $refund, $userId);

            return $refund;
        });
    }

    private function ledger(string $account, string $direction, float $amount, string $description, ?object $source, ?int $userId): void
    {
        FinancialLedgerEntry::create(['entry_number' => 'LED-'.Str::upper(Str::random(16)), 'entry_date' => today(), 'account_code' => $account, 'direction' => $direction, 'amount' => $amount, 'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(), 'description' => $description, 'created_by' => $userId]);
    }
}

