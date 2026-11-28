<?php

namespace App\Services\App;

use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Cashier Zad Pro: how the customer's digital receipt looks.
 * "classic" is the current card receipt (the default); "thermal" is the long
 * till-roll invoice with the store's logo, order number and address.
 * Settings live in cashier_store_settings.settings under the "receipt" key.
 */
class ReceiptStyleService
{
    public const STYLES = ['classic', 'thermal'];

    /** Launch period: removing the Zad Sync logo is free. Flip to false to make it a paid add-on. */
    public const HIDE_BRANDING_FREE = true;

    public const DEFAULTS = [
        'style' => 'classic',
        'show_logo' => true,
        'show_address' => true,
        'address' => null,
        'show_english' => true,
        'show_tax_qr' => true,
        'thank_you' => 'شكراً لك… بالعافية',
        'social' => null,
        'hide_zad_branding' => false,
    ];

    public function ready(): bool
    {
        return Schema::hasTable('cashier_store_settings');
    }

    public function settings(Store $store): array
    {
        $saved = $this->raw($store)['receipt'] ?? [];
        $s = array_replace(self::DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS));
        $s['style'] = in_array($s['style'], self::STYLES, true) ? $s['style'] : 'classic';
        if (! self::HIDE_BRANDING_FREE && ! $this->canHideBranding($store)) {
            $s['hide_zad_branding'] = false;
        }

        return $s;
    }

    public function payload(Store $store): array
    {
        return $this->settings($store) + [
            'hide_branding_free' => self::HIDE_BRANDING_FREE,
            'default_address' => $this->defaultAddress($store),
            'store' => [
                'name' => $store->name_ar ?: $store->name_en,
                'name_en' => $store->name_en,
                'logo_url' => $this->logoUrl($store),
                'vat_number' => $store->vat_number ?? null,
            ],
            'latest_receipt_url' => $this->latestUrl($store),
        ];
    }

    public function rules(): array
    {
        return [
            'style' => ['sometimes', 'string', 'in:'.implode(',', self::STYLES)],
            'show_logo' => ['sometimes', 'boolean'],
            'show_address' => ['sometimes', 'boolean'],
            'address' => ['sometimes', 'nullable', 'string', 'max:160'],
            'show_english' => ['sometimes', 'boolean'],
            'show_tax_qr' => ['sometimes', 'boolean'],
            'thank_you' => ['sometimes', 'nullable', 'string', 'max:80'],
            'social' => ['sometimes', 'nullable', 'string', 'max:60'],
            'hide_zad_branding' => ['sometimes', 'boolean'],
        ];
    }

    public function save(Store $store, array $data): array
    {
        $next = array_replace($this->settings($store), array_intersect_key($data, self::DEFAULTS));
        foreach (['address', 'thank_you', 'social'] as $k) {
            $next[$k] = ($v = trim((string) ($next[$k] ?? ''))) === '' ? null : $v;
        }
        if (! self::HIDE_BRANDING_FREE && ! $this->canHideBranding($store)) {
            $next['hide_zad_branding'] = false;
        }
        $raw = $this->raw($store);
        $raw['receipt'] = $next;
        DB::table('cashier_store_settings')->updateOrInsert(
            ['store_id' => $store->id],
            ['settings' => json_encode($raw, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
        );

        return $this->payload($store);
    }

    /** Thermal settings for this receipt's store, or null when the store keeps the classic receipt. */
    public function thermalFor(SaleReceipt $receipt): ?array
    {
        if (! $this->ready() || ! $receipt->store_id) {
            return null;
        }
        $store = Store::query()->find($receipt->store_id);
        if (! $store) {
            return null;
        }
        $s = $this->settings($store);
        if ($s['style'] !== 'thermal' || ! $this->entitled($store)) {
            return null;
        }

        return $s + [
            'store_name' => $receipt->store_name ?: ($store->name_ar ?: $store->name_en),
            'store_name_en' => $store->name_en,
            'logo' => $s['show_logo'] ? $this->logoDataUri($store) : null,
            'address_text' => $s['show_address'] ? ($s['address'] ?: $this->defaultAddress($store)) : null,
            'order_label' => $receipt->order?->number ? (string) $receipt->order->number : (string) $this->sequence($receipt),
            'english' => $s['show_english'] ? $this->englishNames($receipt) : [],
            'store_url' => rtrim((string) env('ZAD_PUBLIC_WEB_URL', 'https://zad-sync-sa.onrender.com'), '/').'/stores/'.$store->id,
        ];
    }

    /** Running number of this sale inside its store (1, 2, 3…), shown big like a till order number. */
    public function sequence(SaleReceipt $receipt): int
    {
        return SaleReceipt::query()->where('store_id', $receipt->store_id)->where('id', '<=', $receipt->id)->count();
    }

    public function logoDataUri(Store $store): ?string
    {
        $path = $store->logo_path;
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        try {
            $bytes = Storage::disk('public')->get(ltrim(preg_replace('#^/?storage/#', '', $path), '/'));
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 600_000) {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function logoUrl(Store $store): ?string
    {
        $path = $store->logo_path;
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk('public')->url(ltrim(preg_replace('#^/?storage/#', '', $path), '/'));
    }

    private function englishNames(SaleReceipt $receipt): array
    {
        $ids = array_values(array_filter(array_map(fn ($i) => $i['product_id'] ?? null, $receipt->items ?? [])));

        return $ids ? Product::query()->whereIn('id', $ids)->whereNotNull('name_en')->pluck('name_en', 'id')->all() : [];
    }

    private function defaultAddress(Store $store): ?string
    {
        $city = null;
        if ($store->city_id && Schema::hasTable('cities')) {
            $row = DB::table('cities')->where('id', $store->city_id)->first();
            $city = $row->name_ar ?? ($row->name ?? null);
        }

        return $city ?: null;
    }

    private function latestUrl(Store $store): ?string
    {
        $token = SaleReceipt::query()->where('store_id', $store->id)->latest('id')->value('public_token');

        return $token ? rtrim((string) config('app.url'), '/').'/r/'.$token : null;
    }

    /** The long receipt is a Cashier Pro feature; when Pro ends the store falls back to the classic receipt. */
    public function entitled(Store $store): bool
    {
        $family = $store->productiveFamily;

        return (bool) (app(CashierZadService::class)->entitlement($family)['features']['receipt_style'] ?? false);
    }

    private function canHideBranding(Store $store): bool
    {
        // Paid add-on later; while HIDE_BRANDING_FREE is on, every Pro store can hide it.
        return self::HIDE_BRANDING_FREE;
    }

    private function raw(Store $store): array
    {
        if (! $this->ready()) {
            return [];
        }
        $row = DB::table('cashier_store_settings')->where('store_id', $store->id)->value('settings');

        return is_string($row) ? (json_decode($row, true) ?: []) : [];
    }
}
