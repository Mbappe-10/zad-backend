<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\SaleReceipt;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;

/**
 * One non-tax sale receipt for every sale on the platform.
 * Receipts are issued only for actual paid sales, after payment is confirmed; the order and payment
 * flows are only observed, never changed.
 */
class SaleReceiptService
{
    public const TYPE_LABELS = [
        SaleReceipt::TYPE_PLATFORM => 'طلب عبر المنصة',
        SaleReceipt::TYPE_QUICK => 'بيع سريع',
        SaleReceipt::TYPE_CASHIER => 'كاشير مربوط',
    ];

    public const CHANNEL_LABELS = [
        'online' => 'أونلاين',
        'door' => 'عند الباب',
        'bazaar' => 'معرض',
        'shop' => 'المحل',
    ];

    public const METHOD_LABELS = [
        'creditcard' => 'بطاقة',
        'mada' => 'مدى',
        'applepay' => 'Apple Pay',
        'apple_pay' => 'Apple Pay',
        'stcpay' => 'STC Pay',
        'sponsored' => 'ممول من زاد سينك',
        'cash' => 'كاش',
        'network' => 'شبكة (مدى)',
        'other' => 'أخرى',
        'transfer' => 'تحويل بنكي',
        'payment_link' => 'رابط دفع',
        'online' => 'دفع إلكتروني',
    ];

    public const STATUS_LABELS = ['paid' => 'مدفوع', 'refunded' => 'مسترد', 'cancelled' => 'ملغي'];

    private static ?bool $available = null;

    public function available(): bool
    {
        return self::$available ??= Schema::hasTable('sale_receipts');
    }

    /** Issues (or refreshes) the receipt of a paid platform order. Never throws into the order flow. */
    public function syncOrder(Order $order): ?SaleReceipt
    {
        try {
            if (! $this->available()) {
                return null;
            }
            $existing = SaleReceipt::query()->where('order_id', $order->id)->first();
            if ($existing) {
                return $this->refresh($existing, $order);
            }
            // Only an actual paid sale gets a receipt.
            if (! $order->isPaid()) {
                return null;
            }

            $receipt = DB::transaction(function () use ($order): SaleReceipt {
                $receipt = SaleReceipt::query()->create($this->orderAttributes($order) + [
                    'public_token' => Str::random(40),
                    'sale_type' => SaleReceipt::TYPE_PLATFORM,
                    'channel' => 'online',
                    'order_id' => $order->id,
                    'issued_at' => now(),
                ]);
                $receipt->update(['number' => $this->number($receipt)]);

                return $receipt;
            });
            app(\App\Services\App\CashierInventoryService::class)->applySale($receipt);

            return $receipt;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return SaleReceipt::query()->where('order_id', $order->id)->first();
        } catch (\Throwable $e) {
            Log::warning('sale receipt issue failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * A direct sale recorded by the family's cashier. The money is paid to the family
     * directly (cash, network, transfer…); Zad Sync only records it and issues the receipt.
     *
     * @param  array<int, array{name: string, quantity: int, unit_price: float, product_id?: int|null}>  $items
     */
    public function issueQuickSale(Store $store, array $items, string $method, string $channel, ?string $phone, float $discount = 0, array $actor = []): SaleReceipt
    {
        $subtotal = round(array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_price'], $items)), 2);
        $discount = min(max(0, round($discount, 2)), $subtotal);

        $receipt = DB::transaction(function () use ($store, $items, $method, $channel, $phone, $subtotal, $discount, $actor): SaleReceipt {
            $receipt = SaleReceipt::query()->create([
                'public_token' => Str::random(40),
                'sale_type' => SaleReceipt::TYPE_QUICK,
                'channel' => $channel,
                'store_id' => $store->id,
                'productive_family_id' => $store->productive_family_id,
                'store_name' => $store->name_ar ?: ($store->name_en ?: null),
                'customer_phone' => $phone,
                'payment_method' => $method,
                'subtotal' => $subtotal,
                'delivery_fee' => 0,
                'discount' => $discount,
                'total' => round($subtotal - $discount, 2),
                'items' => array_map(fn ($i) => [
                    'product_id' => $i['product_id'] ?? null,
                    'name' => $i['name'],
                    'quantity' => $i['quantity'],
                    'unit_price' => round($i['unit_price'], 2),
                    'total' => round($i['quantity'] * $i['unit_price'], 2),
                ], $items),
                'status' => 'paid',
                'issued_at' => now(),
            ] + array_intersect_key($actor, array_flip(['cashier_staff_id', 'cashier_shift_id', 'staff_name'])));
            $receipt->update(['number' => $this->number($receipt)]);

            return $receipt;
        });
        app(\App\Services\App\CashierInventoryService::class)->applySale($receipt);

        return $receipt;
    }

    /** Issues receipts for paid orders that have none yet (older orders, missed events). */
    public function syncMissing(int $limit = 300): int
    {
        if (! $this->available()) {
            return 0;
        }
        $orders = Order::query()
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sale_receipts')->whereColumn('sale_receipts.order_id', 'orders.id'))
            ->orderByDesc('id')->limit($limit)->get();
        $count = 0;
        foreach ($orders as $order) {
            $count += $this->syncOrder($order) ? 1 : 0;
        }

        return $count;
    }

    public function number(SaleReceipt $receipt): string
    {
        $prefix = ['platform' => 'ZS-R', 'quick' => 'ZS-M', 'cashier' => 'ZS-C'][$receipt->sale_type] ?? 'ZS';

        return $prefix.'-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT);
    }

    public function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '' || strlen($digits) < 4) {
            return null;
        }
        if (str_starts_with($digits, '966')) {
            $digits = '0'.substr($digits, 3);
        }

        return substr($digits, 0, 2).'•• ••• '.substr($digits, -3);
    }

    public function publicUrl(SaleReceipt $receipt): string
    {
        return rtrim((string) config('app.url'), '/').'/r/'.$receipt->public_token;
    }

    public function whatsappUrl(SaleReceipt $receipt): ?string
    {
        $number = preg_replace('/\D+/', '', (string) config('services.zad.support_whatsapp', env('ZAD_SUPPORT_WHATSAPP', '')));
        if ($number === '') {
            return null;
        }
        $text = 'السلام عليكم، عندي استفسار عن الإيصال '.$receipt->number
            .($receipt->order?->number ? ' — الطلب رقم '.$receipt->order->number : '');

        return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }

    /** Shape shared by the family, customer and admin APIs. Never exposes the raw phone. */
    public function payload(SaleReceipt $receipt, bool $admin = false): array
    {
        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'order_id' => $receipt->order_id,
            'order_number' => $receipt->order?->number,
            'sale_type' => $receipt->sale_type,
            'sale_type_label' => self::TYPE_LABELS[$receipt->sale_type] ?? $receipt->sale_type,
            'channel' => $receipt->channel,
            'channel_label' => self::CHANNEL_LABELS[$receipt->channel] ?? $receipt->channel,
            'store_id' => $receipt->store_id,
            'store_name' => $receipt->store_name,
            'customer' => $this->maskPhone($receipt->customer_phone),
            'payment_method' => $receipt->payment_method,
            'payment_method_label' => self::METHOD_LABELS[$receipt->payment_method ?? ''] ?? ($receipt->payment_method ?: 'دفع إلكتروني'),
            'items' => $receipt->items ?? [],
            'subtotal' => (float) $receipt->subtotal,
            'delivery_fee' => (float) $receipt->delivery_fee,
            'discount' => (float) $receipt->discount,
            'total' => (float) $receipt->total,
            'status' => $receipt->status,
            'status_label' => self::STATUS_LABELS[$receipt->status] ?? $receipt->status,
            'issued_at' => $receipt->issued_at?->toIso8601String(),
            'opened_at' => $receipt->opened_at?->toIso8601String(),
            'url' => $this->publicUrl($receipt),
            'staff_name' => $receipt->sale_type === SaleReceipt::TYPE_PLATFORM ? null : ($receipt->staff_name ?? null),
        ] + ($admin ? ['public_token' => $receipt->public_token] : []);
    }

    public function pdf(SaleReceipt $receipt): string
    {
        $temp = storage_path('app/private/sale-receipt-mpdf');
        File::ensureDirectoryExists($temp);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
            'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 12, 'margin_bottom' => 12]);
        $pdf->SetDirectionality('rtl');
        $pdf->SetTitle('إيصال بيع '.$receipt->number);
        $images = [];
        $logo = public_path('app/assets/assets/contracts/zad_logo.png');
        if (File::isFile($logo)) {
            $pdf->imageVars['logo'] = File::get($logo);
            $images['logo'] = 'var:logo';
        }
        $storeLogo = $this->storeLogoBytes($receipt);
        if ($storeLogo !== null) {
            $pdf->imageVars['store'] = $storeLogo;
            $images['store'] = 'var:store';
        }
        $pdf->WriteHTML(view('receipts.sale', [
            'r' => $this->payload($receipt),
            'images' => $images,
            'qr' => class_exists(\Mpdf\QrCode\QrCode::class),
        ])->render());

        return $pdf->Output('', 'S');
    }

    public function filename(SaleReceipt $receipt): string
    {
        return 'receipt-'.$receipt->number.'.pdf';
    }

    private function refresh(SaleReceipt $receipt, Order $order): SaleReceipt
    {
        $status = match (true) {
            $order->payment_status === Order::PAYMENT_REFUNDED => 'refunded',
            $order->status === Order::STATUS_CANCELLED && ! $order->isPaid() => 'cancelled',
            default => 'paid',
        };
        $changes = [];
        if ($receipt->status !== $status) {
            $changes['status'] = $status;
        }
        if (empty($receipt->items)) {
            $items = $this->items($order);
            if ($items !== []) {
                $changes['items'] = $items;
            }
        }
        if ($changes !== []) {
            $receipt->update($changes);
        }

        return $receipt;
    }

    private function orderAttributes(Order $order): array
    {
        $store = $order->store_id ? Store::query()->find($order->store_id) : null;
        $method = Payment::query()->where('order_id', $order->id)->where('status', 'paid')->latest('id')->value('method');
        $phone = $order->contact_phone;
        if (! $phone && $order->customer_id) {
            $phone = Customer::query()->whereKey($order->customer_id)->value('phone');
        }

        return [
            'store_id' => $order->store_id,
            'productive_family_id' => $store?->productive_family_id,
            'store_name' => $store?->name_ar ?: ($store?->name_en ?: null),
            'customer_id' => $order->customer_id,
            'customer_phone' => $phone,
            'payment_method' => $method ? strtolower((string) $method) : 'online',
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'items' => $this->items($order),
            'status' => $order->payment_status === Order::PAYMENT_REFUNDED ? 'refunded' : 'paid',
        ];
    }

    private function items(Order $order): array
    {
        return OrderItem::query()->where('order_id', $order->id)->orderBy('id')
            ->get(['product_id', 'product_name', 'quantity', 'unit_price', 'total'])
            ->map(fn (OrderItem $i): array => [
                'product_id' => $i->product_id,
                'name' => (string) $i->product_name,
                'quantity' => (int) $i->quantity,
                'unit_price' => round((float) $i->unit_price, 2),
                'total' => round((float) $i->total, 2),
            ])->values()->all();
    }

    private function storeLogoBytes(SaleReceipt $receipt): ?string
    {
        try {
            $path = $receipt->store_id ? Store::query()->whereKey($receipt->store_id)->value('logo_path') : null;
            if (! $path || str_starts_with($path, 'http')) {
                return null;
            }
            $path = ltrim(preg_replace('#^/?storage/#', '', $path), '/');
            $bytes = Storage::disk('public')->get($path);
            if (! is_string($bytes) || $bytes === '') {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            return in_array($mime, ['image/png', 'image/jpeg'], true) ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
