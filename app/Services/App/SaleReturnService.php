<?php

namespace App\Services\App;

use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Returns and exchanges at the cashier. Each return lists the lines and quantities
 * that came back; with VAT it is a credit note, and when the store is linked to
 * Fatoora it is signed and reported in the same chain as the invoice.
 * An exchange is a return followed by a new sale.
 */
class SaleReturnService
{
    public const REFUND_METHODS = ['cash', 'network', 'transfer', 'store_credit', 'exchange'];

    public const REFUND_LABELS = [
        'cash' => 'كاش', 'network' => 'شبكة (مدى)', 'transfer' => 'تحويل بنكي',
        'store_credit' => 'رصيد للعميل', 'exchange' => 'استبدال بمنتج آخر',
    ];

    public function ready(): bool
    {
        return Schema::hasTable('sale_returns');
    }

    /** Lines of the sale with how many can still be returned. */
    public function returnable(SaleReceipt $receipt): array
    {
        $returned = $this->returnedQuantities($receipt);

        return array_values(array_map(fn ($item, $i) => [
            'line' => $i,
            'name' => (string) $item['name'],
            'unit_price' => (float) $item['unit_price'],
            'quantity' => (int) $item['quantity'],
            'returned' => $returned[$i] ?? 0,
            'remaining' => max(0, (int) $item['quantity'] - ($returned[$i] ?? 0)),
        ], $receipt->items ?? [], array_keys($receipt->items ?? [])));
    }

    /**
     * @param  array<int, array{line: int, quantity: int}>  $lines
     */
    public function create(SaleReceipt $receipt, array $lines, string $reason, string $method, bool $restock, array $actor = []): SaleReturn
    {
        abort_unless($receipt->sale_type !== SaleReceipt::TYPE_PLATFORM, 422, 'مرتجع طلبات الأونلاين يكون من صفحة الطلب.');
        abort_unless($receipt->status === 'paid', 422, 'هذا الإيصال ملغي أو مسترد.');

        $return = DB::transaction(function () use ($receipt, $lines, $reason, $method, $restock, $actor): SaleReturn {
            $locked = SaleReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $items = $locked->items ?? [];
            $returned = $this->returnedQuantities($locked);
            $picked = [];
            foreach ($lines as $l) {
                $i = (int) $l['line'];
                $q = (int) $l['quantity'];
                if ($q <= 0) {
                    continue;
                }
                abort_unless(isset($items[$i]), 422, 'سطر غير موجود في الإيصال.');
                $left = (int) $items[$i]['quantity'] - ($returned[$i] ?? 0) - ($picked[$i]['quantity'] ?? 0);
                abort_if($q > $left, 422, 'الكمية المرتجعة من «'.$items[$i]['name'].'» أكثر من المتبقي ('.$left.').');
                $picked[$i] = [
                    'line' => $i,
                    'product_id' => $items[$i]['product_id'] ?? null,
                    'name' => (string) $items[$i]['name'],
                    'quantity' => $q + ($picked[$i]['quantity'] ?? 0),
                    'unit_price' => round((float) $items[$i]['unit_price'], 2),
                ];
            }
            abort_if($picked === [], 422, 'اختر المنتجات المرتجعة.');
            $picked = array_values(array_map(fn ($p) => $p + ['total' => round($p['quantity'] * $p['unit_price'], 2)], $picked));

            $subtotal = round(array_sum(array_column($picked, 'total')), 2);
            // The sale's discount is shared in proportion to what came back.
            $discount = (float) $locked->subtotal > 0 ? round((float) $locked->discount * $subtotal / (float) $locked->subtotal, 2) : 0.0;
            $net = round($subtotal - $discount, 2);
            $vat = $locked->seller_vat_number ? round($net * StoreTaxService::RATE / 100, 2) : 0.0;

            $return = SaleReturn::query()->create([
                'store_id' => $locked->store_id,
                'sale_receipt_id' => $locked->id,
                'public_token' => Str::random(40),
                'items' => $picked,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'vat_amount' => $vat,
                'total' => round($net + $vat, 2),
                'reason' => mb_substr(trim($reason), 0, 200),
                'refund_method' => $method,
                'restocked' => $restock,
                'cashier_staff_id' => $actor['cashier_staff_id'] ?? null,
                'staff_name' => $actor['staff_name'] ?? null,
                'issued_at' => now(),
            ]);
            $return->update(['number' => 'ZS-CN-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)]);
            if (Schema::hasColumn('sale_receipts', 'returned_total')) {
                $locked->forceFill(['returned_total' => round((float) $locked->returned_total + (float) $return->total, 2)])->save();
            }
            if ($restock) {
                foreach ($picked as $p) {
                    if ($p['product_id']) {
                        Product::query()->whereKey($p['product_id'])->where('store_id', $locked->store_id)
                            ->where('track_stock', true)->increment('stock_quantity', $p['quantity']);
                    }
                }
            }

            return $return;
        });

        $doc = app(ZatcaService::class)->issueForReturn($return->refresh());
        if ($doc && $doc->invoice_type === 'standard') {
            app(ZatcaService::class)->submit($doc);
        }

        return $return->refresh();
    }

    public function payload(SaleReturn $return): array
    {
        $doc = app(ZatcaService::class)->ready()
            ? \App\Models\ZatcaDocument::query()->where('sale_return_id', $return->id)->first() : null;

        return [
            'id' => $return->id,
            'number' => $return->number,
            'receipt_id' => $return->sale_receipt_id,
            'items' => $return->items,
            'subtotal' => (float) $return->subtotal,
            'discount' => (float) $return->discount,
            'vat_amount' => (float) $return->vat_amount,
            'total' => (float) $return->total,
            'reason' => $return->reason,
            'refund_method' => $return->refund_method,
            'refund_method_label' => self::REFUND_LABELS[$return->refund_method] ?? $return->refund_method,
            'restocked' => (bool) $return->restocked,
            'staff_name' => $return->staff_name,
            'issued_at' => $return->issued_at?->toISOString(),
            'is_credit_note' => (float) $return->vat_amount > 0,
            'zatca_status' => $doc?->status,
            'url' => rtrim((string) config('app.url'), '/').'/c/'.$return->public_token,
        ];
    }

    /** @return array<int, int> line index => quantity already returned */
    private function returnedQuantities(SaleReceipt $receipt): array
    {
        if (! $this->ready()) {
            return [];
        }
        $out = [];
        foreach (SaleReturn::query()->where('sale_receipt_id', $receipt->id)->pluck('items') as $items) {
            foreach ((array) (is_string($items) ? json_decode($items, true) : $items) as $p) {
                $out[(int) $p['line']] = ($out[(int) $p['line']] ?? 0) + (int) $p['quantity'];
            }
        }

        return $out;
    }
}
