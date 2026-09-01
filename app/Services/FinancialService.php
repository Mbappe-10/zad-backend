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
                throw ValidationException::withMessages(['wallet' => 'المحفظة مجمدة.']);
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
                'amount' => ['قيمة الرصيد المعلق يجب أن تكون أكبر من صفر.'],
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
                    'transaction' => ['الحركة المالية ليست في حالة تسمح بتحريرها.'],
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
                $transaction->description ?? 'تحرير مستحقات طلب',
                $transaction,
                $userId,
            );

            return $transaction->fresh();
        });
    }

    public function requestPayout(Wallet $wallet, array $data, ?int $userId): Payout
    {
        return DB::transaction(function () use ($wallet, $data, $userId) {
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($wallet->is_frozen) {
                throw ValidationException::withMessages(['wallet' => 'المحفظة مجمدة.']);
            }
            if (Payout::query()->where('wallet_id', $wallet->id)
                ->whereIn('status', ['pending', 'approved', 'processing'])
                ->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['payout' => 'يوجد طلب سحب مفتوح لهذا الحساب.']);
            }
            $amount = (float) $data['amount'];
            if ($amount <= 0 || $amount > (float) $wallet->available_balance) {
                throw ValidationException::withMessages(['amount' => 'الرصيد المتاح غير كافٍ.']);
            }
            $wallet->available_balance = round((float) $wallet->available_balance - $amount, 2);
            $wallet->pending_balance = round((float) $wallet->pending_balance + $amount, 2);
            $wallet->save();

            return Payout::create([...$data, 'wallet_id' => $wallet->id, 'reference' => 'PAY-'.Str::upper(Str::random(14)), 'fee' => $data['fee'] ?? 0, 'net_amount' => round($amount - (float) ($data['fee'] ?? 0), 2), 'status' => 'pending', 'requested_by' => $userId]);
        });
    }

    public function decidePayout(Payout $payout, string $decision, ?int $userId): Payout
    {
        return DB::transaction(function () use ($payout, $decision, $userId) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'تم اتخاذ قرار على طلب الصرف مسبقًا.']);
            }
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($payout->wallet_id);
            if ($decision === 'reject') {
                $wallet->pending_balance -= $payout->amount;
                $wallet->available_balance += $payout->amount;
                $wallet->save();
                $payout->update(['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => now()]);
            } else {
                $wallet->pending_balance -= $payout->amount;
                $wallet->save();
                $payout->update(['status' => 'paid', 'approved_by' => $userId, 'approved_at' => now(), 'paid_at' => now()]);
                $this->ledger('cash', 'debit', (float) $payout->net_amount, 'صرف مستحقات', $payout, $userId);
            }

            return $payout->fresh();
        });
    }

    public function refund(Payment $payment, array $data, ?int $userId): Refund
    {
        return DB::transaction(function () use ($payment, $data, $userId) {
            $refunded = (float) Refund::where('payment_id', $payment->id)->whereIn('status', ['approved', 'completed'])->sum('amount');
            $amount = (float) $data['amount'];
            if ($amount <= 0 || $refunded + $amount > (float) $payment->gross_amount) {
                throw ValidationException::withMessages(['amount' => 'قيمة الاسترداد تتجاوز المبلغ القابل للاسترداد.']);
            }
            $refund = Refund::create(['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'reference' => 'REF-'.Str::upper(Str::random(14)), 'amount' => $amount, 'status' => 'completed', 'reason' => $data['reason'], 'requested_by' => $userId, 'approved_by' => $userId, 'approved_at' => now(), 'refunded_at' => now()]);
            $this->ledger('customer_refunds', 'debit', $amount, 'استرداد دفعة', $refund, $userId);

            return $refund;
        });
    }

    private function ledger(string $account, string $direction, float $amount, string $description, ?object $source, ?int $userId): void
    {
        FinancialLedgerEntry::create(['entry_number' => 'LED-'.Str::upper(Str::random(16)), 'entry_date' => today(), 'account_code' => $account, 'direction' => $direction, 'amount' => $amount, 'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(), 'description' => $description, 'created_by' => $userId]);
    }
}
