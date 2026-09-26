<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\ProductiveFamily;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminPaymentRegisterService
{
    /** @return array{paginator: LengthAwarePaginator, summary: array<string, int|float>} */
    public function paginate(array $filters, int $perPage): array
    {
        $query = $this->filteredQuery($filters);
        $summary = $this->summary(clone $query);
        $paginator = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('source_id')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (object $row): array => $this->payload($row)),
        );

        return compact('paginator', 'summary');
    }

    public function exportQuery(array $filters): Builder
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('source_id');
    }

    public function payload(object $row): array
    {
        return [
            'id' => $row->source_type.':'.$row->source_id,
            'source_type' => $row->source_type,
            'source_id' => (int) $row->source_id,
            'payment_id' => $row->payment_id === null ? null : (int) $row->payment_id,
            'reference' => $row->reference,
            'order_number' => $row->order_number,
            'customer_name' => $row->counterparty_name,
            'customer_email' => $row->counterparty_email,
            'beneficiary_name' => $row->counterparty_name,
            'amount' => (float) $row->amount,
            'fee_amount' => (float) $row->fee_amount,
            'net_amount' => (float) $row->net_amount,
            'refunded_amount' => (float) $row->refunded_amount,
            'currency' => $row->currency ?: 'SAR',
            'status' => $row->status,
            'method' => $row->method ?: 'other',
            'gateway_name' => $row->gateway_name,
            'transaction_id' => $row->transaction_id,
            'city_name' => $row->city_name,
            'paid_at' => $row->occurred_at,
            'created_at' => $row->created_at,
            'failure_reason' => $row->failure_reason,
            'direction' => $row->direction,
            'category' => $row->category,
            'description' => $row->description,
        ];
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = DB::query()->fromSub($this->unionQuery(), 'movements');

        if (filled($filters['search'] ?? null)) {
            $search = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
            $query->where(function (Builder $query) use ($search): void {
                foreach ([
                    'reference',
                    'order_number',
                    'counterparty_name',
                    'counterparty_email',
                    'transaction_id',
                    'description',
                ] as $column) {
                    $method = $column === 'reference' ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}(
                        'LOWER(COALESCE('.$column.", '')) LIKE ?",
                        [$search],
                    );
                }
            });
        }

        foreach ([
            'status' => 'status',
            'method' => 'method',
            'gateway' => 'gateway_name',
            'direction' => 'direction',
            'category' => 'category',
        ] as $filter => $column) {
            if (filled($filters[$filter] ?? null) && $filters[$filter] !== 'all') {
                $query->where($column, $filters[$filter]);
            }
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('occurred_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('occurred_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    private function unionQuery(): Builder
    {
        return $this->paymentQuery()
            ->unionAll($this->gatewayFeeQuery())
            ->unionAll($this->payoutQuery())
            ->unionAll($this->refundQuery())
            ->unionAll($this->manualLedgerQuery());
    }

    private function paymentQuery(): Builder
    {
        $refundTotals = DB::table('refunds')
            ->select('payment_id')
            ->selectRaw("SUM(CASE WHEN status IN ('approved', 'completed') THEN amount ELSE 0 END) AS refunded_amount")
            ->groupBy('payment_id');

        return DB::table('payments as payment')
            ->leftJoin('orders as orders', 'orders.id', '=', 'payment.order_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'orders.customer_id')
            ->leftJoin('cities as city', 'city.id', '=', 'orders.city_id')
            ->leftJoin('payment_providers as provider', 'provider.id', '=', 'payment.provider_id')
            ->leftJoinSub($refundTotals, 'refund_total', fn ($join) =>
                $join->on('refund_total.payment_id', '=', 'payment.id'))
            ->selectRaw("'payment' AS source_type")
            ->selectRaw('payment.id AS source_id')
            ->selectRaw('payment.id AS payment_id')
            ->selectRaw('payment.reference AS reference')
            ->selectRaw('orders.number AS order_number')
            ->selectRaw('customer.name AS counterparty_name')
            ->selectRaw('customer.email AS counterparty_email')
            ->selectRaw('payment.gross_amount AS amount')
            ->selectRaw('payment.provider_fee AS fee_amount')
            ->selectRaw('payment.net_amount AS net_amount')
            ->selectRaw('COALESCE(refund_total.refunded_amount, 0) AS refunded_amount')
            ->selectRaw('payment.currency AS currency')
            ->selectRaw("CASE
                WHEN payment.status = 'paid' AND COALESCE(refund_total.refunded_amount, 0) >= payment.gross_amount THEN 'refunded'
                WHEN payment.status = 'paid' AND COALESCE(refund_total.refunded_amount, 0) > 0 THEN 'partially_refunded'
                ELSE payment.status END AS status")
            ->selectRaw("COALESCE(payment.method, 'other') AS method")
            ->selectRaw('provider.name AS gateway_name')
            ->selectRaw('payment.provider_reference AS transaction_id')
            ->selectRaw('city.name_ar AS city_name')
            ->selectRaw('COALESCE(payment.paid_at, payment.created_at) AS occurred_at')
            ->selectRaw('payment.created_at AS created_at')
            ->selectRaw('NULL AS failure_reason')
            ->selectRaw("'inbound' AS direction")
            ->selectRaw("'customer_payment' AS category")
            ->selectRaw("'تحصيل دفعة طلب من العميل' AS description");
    }

    private function gatewayFeeQuery(): Builder
    {
        return DB::table('payments as payment')
            ->leftJoin('orders as orders', 'orders.id', '=', 'payment.order_id')
            ->leftJoin('payment_providers as provider', 'provider.id', '=', 'payment.provider_id')
            ->where('payment.status', 'paid')
            ->where('payment.provider_fee', '>', 0)
            ->selectRaw("'gateway_fee' AS source_type")
            ->selectRaw('payment.id AS source_id')
            ->selectRaw('payment.id AS payment_id')
            ->selectRaw('payment.reference AS reference')
            ->selectRaw('orders.number AS order_number')
            ->selectRaw('provider.name AS counterparty_name')
            ->selectRaw('NULL AS counterparty_email')
            ->selectRaw('payment.provider_fee AS amount')
            ->selectRaw('0 AS fee_amount')
            ->selectRaw('payment.provider_fee AS net_amount')
            ->selectRaw('0 AS refunded_amount')
            ->selectRaw('payment.currency AS currency')
            ->selectRaw("'paid' AS status")
            ->selectRaw("'gateway' AS method")
            ->selectRaw('provider.name AS gateway_name')
            ->selectRaw('payment.provider_reference AS transaction_id')
            ->selectRaw('NULL AS city_name')
            ->selectRaw('COALESCE(payment.paid_at, payment.created_at) AS occurred_at')
            ->selectRaw('payment.created_at AS created_at')
            ->selectRaw('NULL AS failure_reason')
            ->selectRaw("'outbound' AS direction")
            ->selectRaw("'gateway_fee' AS category")
            ->selectRaw("'رسوم بوابة الدفع' AS description");
    }

    private function payoutQuery(): Builder
    {
        return DB::table('payouts as payout')
            ->join('wallets as wallet', 'wallet.id', '=', 'payout.wallet_id')
            ->leftJoin('productive_families as family', function ($join): void {
                $join->on('family.id', '=', 'wallet.owner_id')
                    ->whereIn('wallet.owner_type', [
                        ProductiveFamily::class,
                        'family',
                        'productive_family',
                    ]);
            })
            ->leftJoin('drivers as driver', function ($join): void {
                $join->on('driver.id', '=', 'wallet.owner_id')
                    ->whereIn('wallet.owner_type', [Driver::class, 'driver', 'courier']);
            })
            ->selectRaw("'payout' AS source_type")
            ->selectRaw('payout.id AS source_id')
            ->selectRaw('NULL AS payment_id')
            ->selectRaw('payout.reference AS reference')
            ->selectRaw('NULL AS order_number')
            ->selectRaw('COALESCE(family.owner_name, driver.name, payout.account_name) AS counterparty_name')
            ->selectRaw('COALESCE(family.email, NULL) AS counterparty_email')
            ->selectRaw('payout.net_amount AS amount')
            ->selectRaw('payout.fee AS fee_amount')
            ->selectRaw('payout.net_amount AS net_amount')
            ->selectRaw('0 AS refunded_amount')
            ->selectRaw('wallet.currency AS currency')
            ->selectRaw("CASE
                WHEN payout.status IN ('paid', 'completed') THEN 'paid'
                WHEN payout.status IN ('rejected', 'cancelled') THEN 'cancelled'
                ELSE 'pending' END AS status")
            ->selectRaw("'bank_transfer' AS method")
            ->selectRaw('payout.bank_name AS gateway_name')
            ->selectRaw('NULL AS transaction_id')
            ->selectRaw('NULL AS city_name')
            ->selectRaw('COALESCE(payout.paid_at, payout.created_at) AS occurred_at')
            ->selectRaw('payout.created_at AS created_at')
            ->selectRaw('NULL AS failure_reason')
            ->selectRaw("'outbound' AS direction")
            ->selectRaw("CASE
                WHEN payout.account_role = 'family' OR family.id IS NOT NULL THEN 'family_payout'
                WHEN payout.account_role = 'driver' OR driver.id IS NOT NULL THEN 'driver_payout'
                ELSE 'payout' END AS category")
            ->selectRaw("'تحويل مستحقات محفظة' AS description");
    }

    private function refundQuery(): Builder
    {
        return DB::table('refunds as refund')
            ->join('payments as payment', 'payment.id', '=', 'refund.payment_id')
            ->leftJoin('orders as orders', 'orders.id', '=', 'refund.order_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'orders.customer_id')
            ->leftJoin('payment_providers as provider', 'provider.id', '=', 'payment.provider_id')
            ->selectRaw("'refund' AS source_type")
            ->selectRaw('refund.id AS source_id')
            ->selectRaw('payment.id AS payment_id')
            ->selectRaw('refund.reference AS reference')
            ->selectRaw('orders.number AS order_number')
            ->selectRaw('customer.name AS counterparty_name')
            ->selectRaw('customer.email AS counterparty_email')
            ->selectRaw('refund.amount AS amount')
            ->selectRaw('0 AS fee_amount')
            ->selectRaw('refund.amount AS net_amount')
            ->selectRaw('refund.amount AS refunded_amount')
            ->selectRaw('payment.currency AS currency')
            ->selectRaw("CASE
                WHEN refund.status IN ('completed', 'approved') THEN 'paid'
                WHEN refund.status IN ('rejected', 'cancelled') THEN 'cancelled'
                ELSE 'pending' END AS status")
            ->selectRaw("COALESCE(payment.method, 'other') AS method")
            ->selectRaw('provider.name AS gateway_name')
            ->selectRaw('payment.provider_reference AS transaction_id')
            ->selectRaw('NULL AS city_name')
            ->selectRaw('COALESCE(refund.refunded_at, refund.created_at) AS occurred_at')
            ->selectRaw('refund.created_at AS created_at')
            ->selectRaw('NULL AS failure_reason')
            ->selectRaw("'outbound' AS direction")
            ->selectRaw("'refund' AS category")
            ->selectRaw('refund.reason AS description');
    }

    private function manualLedgerQuery(): Builder
    {
        return DB::table('financial_ledger_entries as ledger')
            ->whereNull('ledger.source_type')
            ->selectRaw("'ledger' AS source_type")
            ->selectRaw('ledger.id AS source_id')
            ->selectRaw('NULL AS payment_id')
            ->selectRaw('COALESCE(ledger.external_reference, ledger.entry_number) AS reference')
            ->selectRaw('NULL AS order_number')
            ->selectRaw('ledger.counterparty_name AS counterparty_name')
            ->selectRaw('ledger.counterparty_email AS counterparty_email')
            ->selectRaw('ledger.amount AS amount')
            ->selectRaw('0 AS fee_amount')
            ->selectRaw('ledger.amount AS net_amount')
            ->selectRaw('0 AS refunded_amount')
            ->selectRaw('ledger.currency AS currency')
            ->selectRaw("CASE
                WHEN ledger.status = 'completed' THEN 'paid'
                WHEN ledger.status = 'cancelled' THEN 'cancelled'
                ELSE ledger.status END AS status")
            ->selectRaw("COALESCE(ledger.payment_method, 'other') AS method")
            ->selectRaw('NULL AS gateway_name')
            ->selectRaw('ledger.external_reference AS transaction_id')
            ->selectRaw('NULL AS city_name')
            ->selectRaw('COALESCE(ledger.occurred_at, ledger.created_at) AS occurred_at')
            ->selectRaw('ledger.created_at AS created_at')
            ->selectRaw('NULL AS failure_reason')
            ->selectRaw("CASE WHEN ledger.direction = 'credit' THEN 'inbound' ELSE 'outbound' END AS direction")
            ->selectRaw('ledger.account_code AS category')
            ->selectRaw('ledger.description AS description');
    }

    /** @return array<string, int|float> */
    private function summary(Builder $query): array
    {
        $row = $query->selectRaw('COUNT(*) AS total_transactions')
            ->selectRaw("SUM(CASE WHEN category = 'customer_payment' AND status IN ('paid', 'partially_refunded', 'refunded') THEN amount ELSE 0 END) AS gross_amount")
            ->selectRaw("SUM(CASE WHEN category = 'gateway_fee' AND status = 'paid' THEN amount ELSE 0 END) AS gateway_fees")
            ->selectRaw("SUM(CASE WHEN category IN ('family_payout', 'driver_payout', 'payout') AND status = 'paid' THEN amount ELSE 0 END) AS payouts_amount")
            ->selectRaw("SUM(CASE WHEN category = 'refund' AND status = 'paid' THEN amount ELSE 0 END) AS refunded_amount")
            ->selectRaw("SUM(CASE WHEN direction = 'inbound' AND status IN ('paid', 'partially_refunded', 'refunded') THEN amount WHEN direction = 'outbound' AND status = 'paid' THEN -amount ELSE 0 END) AS net_cash_flow")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count")
            ->first();

        return [
            'total_transactions' => (int) ($row->total_transactions ?? 0),
            'gross_amount' => (float) ($row->gross_amount ?? 0),
            'gateway_fees' => (float) ($row->gateway_fees ?? 0),
            'payouts_amount' => (float) ($row->payouts_amount ?? 0),
            'refunded_amount' => (float) ($row->refunded_amount ?? 0),
            'net_cash_flow' => (float) ($row->net_cash_flow ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
        ];
    }
}
