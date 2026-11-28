<?php

namespace App\Services\App;

use App\Models\SaleReceipt;
use App\Models\Store;
use Illuminate\Support\Facades\Schema;

/**
 * The store's optional VAT details. One record, edited from two places (store details
 * and the Cashier Zad Pro dashboard). Only a store with a VAT number may charge VAT.
 */
class StoreTaxService
{
    public const RATE = 15.0;

    /** Saudi VAT registration number: 15 digits, starts and ends with 3. */
    public const NUMBER_REGEX = '/^3[0-9]{13}3$/';

    private static ?bool $ready = null;

    public function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('stores', 'vat_number') && Schema::hasColumn('sale_receipts', 'vat_amount');
    }

    public function payload(Store $store): array
    {
        $number = $this->ready() ? $store->vat_number : null;

        return [
            'available' => $this->ready(),
            'enabled' => $number !== null && $number !== '',
            'trade_name' => $this->ready() ? $store->tax_trade_name : null,
            'vat_number' => $number,
            'rate' => self::RATE,
        ];
    }

    /** @return array<string, array<int, string>> */
    public function rules(string $prefix = ''): array
    {
        return [
            $prefix.'trade_name' => ['nullable', 'string', 'max:150'],
            $prefix.'vat_number' => ['nullable', 'string', 'regex:'.self::NUMBER_REGEX],
        ];
    }

    /** Saves both fields together. Empty number = VAT off; a number needs the trade name. */
    public function save(Store $store, ?string $tradeName, ?string $number): void
    {
        abort_unless($this->ready(), 503, 'الضريبة غير متاحة حالياً.');
        $tradeName = trim((string) $tradeName) ?: null;
        $number = preg_replace('/\s+/', '', (string) $number) ?: null;
        abort_if($number !== null && ! preg_match(self::NUMBER_REGEX, $number), 422, 'الرقم الضريبي ١٥ رقم، يبدأ بـ 3 وينتهي بـ 3.');
        abort_if($number !== null && $tradeName === null, 422, 'اكتبي الاسم التجاري مع الرقم الضريبي.');
        $unit = app(ZatcaService::class)->activeUnit($store);
        abort_if($unit && $number !== $unit->vat_number, 422, 'متجرك مربوط بمنصة فاتورة بهذا الرقم الضريبي، ما ينفع تغيّره أو تحذفه.');
        $store->forceFill(['tax_trade_name' => $tradeName, 'vat_number' => $number])->save();
    }

    public function vatFor(float $net): float
    {
        return round($net * self::RATE / 100, 2);
    }

    /** Inline SVG of a QR code, drawn on the server so invoice data never leaves it. */
    public function qrSvg(?string $text, int $size = 160): ?string
    {
        if (! $text || ! class_exists(\Mpdf\QrCode\Output\Svg::class)) {
            return null;
        }
        try {
            return (new \Mpdf\QrCode\Output\Svg())->output(new \Mpdf\QrCode\QrCode($text, 'M'), $size);
        } catch (\Throwable) {
            return null;
        }
    }

    /** ZATCA phase-1 QR: base64 TLV of seller name, VAT number, time, total and VAT. */
    public function zatcaQr(SaleReceipt $receipt): ?string
    {
        if (! $receipt->seller_vat_number) {
            return null;
        }
        // Linked to Fatoora: the signed invoice carries the phase-2 QR (with hash and signature).
        $phase2 = $receipt->id ? app(ZatcaService::class)->qrFor($receipt) : null;
        if ($phase2) {
            return $phase2;
        }
        $fields = [
            1 => (string) ($receipt->seller_trade_name ?: $receipt->store_name),
            2 => (string) $receipt->seller_vat_number,
            3 => ($receipt->issued_at ?? now())->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => number_format((float) $receipt->total, 2, '.', ''),
            5 => number_format((float) $receipt->vat_amount, 2, '.', ''),
        ];
        $tlv = '';
        foreach ($fields as $tag => $value) {
            $value = mb_strcut($value, 0, 255, 'UTF-8');
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }
}
