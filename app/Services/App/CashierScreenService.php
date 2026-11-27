<?php

namespace App\Services\App;

use App\Models\CashierDevice;
use App\Models\Category;
use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Services\SaleReceiptService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cashier Zad Pro screens: a TV or iPad shows the family's menu (designed by her),
 * pairs with a code like a streaming TV app, and shows the receipt QR right after
 * a customer pays. The "order from your phone" Zad Sync card is always on.
 */
class CashierScreenService
{
    public const KINDS = ['menu', 'customer'];

    public const THEMES = ['navy', 'light', 'night', 'rose'];

    public const LAYOUTS = ['grid', 'list', 'hero'];

    private const PAIR_TTL = 600;

    private const RECEIPT_SECONDS = 60;

    public function __construct(
        private readonly CashierZadService $cashier,
        private readonly SaleReceiptService $receipts,
    ) {}

    public function enabled(Store $store): bool
    {
        return ! empty($this->cashier->entitlement($store->productiveFamily)['features']['menu_screens']);
    }

    // ------------------------------------------------------------ pairing

    /** A screen asks for a code to show; the owner claims it from her phone. */
    public function startPairing(): array
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (Cache::has('screen_pair_code:'.$code));
        $poll = Str::random(40);
        Cache::put('screen_pair_code:'.$code, $poll, self::PAIR_TTL);
        Cache::put('screen_pair_poll:'.$poll, ['code' => $code, 'token' => null], self::PAIR_TTL);

        return ['code' => $code, 'poll' => $poll, 'expires_in' => self::PAIR_TTL];
    }

    public function claim(Store $store, string $code, string $kind, string $name, ?int $userId): CashierDevice
    {
        $code = preg_replace('/\D/', '', $code);
        $poll = Cache::get('screen_pair_code:'.$code);
        abort_unless(is_string($poll), 422, 'الكود غير صحيح أو انتهى. حدّثي الشاشة وجرّبي مرة ثانية.');

        return DB::transaction(function () use ($store, $code, $poll, $kind, $name, $userId): CashierDevice {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            $limit = $this->cashier->settings()['screens_limit'];
            $count = CashierDevice::query()->where('store_id', $store->id)->whereIn('kind', self::KINDS)->whereNull('revoked_at')->count();
            abort_if($count >= $limit, 422, "وصلتِ للحد: {$limit} شاشات.");
            $token = Str::random(64);
            $device = CashierDevice::query()->create([
                'store_id' => $store->id,
                'kind' => $kind,
                'name' => $name,
                'token_hash' => hash('sha256', $token),
                'settings' => ['show_receipts' => true, 'receipt_devices' => []],
                'created_by_user_id' => $userId,
            ]);
            Cache::put('screen_pair_poll:'.$poll, ['code' => $code, 'token' => $token], self::PAIR_TTL);
            Cache::forget('screen_pair_code:'.$code);

            return $device;
        });
    }

    /** The screen polls; it gets its token once, then the pairing is gone. */
    public function poll(string $poll): ?string
    {
        $row = Cache::get('screen_pair_poll:'.$poll);
        abort_unless(is_array($row), 410, 'انتهى الكود. حدّثي الشاشة.');
        if (empty($row['token'])) {
            return null;
        }
        Cache::forget('screen_pair_poll:'.$poll);

        return $row['token'];
    }

    public function device(?string $token): CashierDevice
    {
        $device = $token ? CashierDevice::query()->where('token_hash', hash('sha256', $token))
            ->whereIn('kind', self::KINDS)->whereNull('revoked_at')->first() : null;
        abort_unless($device !== null, 401, 'هذه الشاشة غير مربوطة.');
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $device;
    }

    // ------------------------------------------------------------ the menu design

    public function defaults(Store $store): array
    {
        return [
            'title' => (string) ($store->name_ar ?: 'المنيو'),
            'tagline' => '',
            'theme' => 'navy',
            'accent' => null,
            'layout' => 'grid',
            'font_scale' => 1.0,
            'rotate_seconds' => 12,
            'show_prices' => true,
            'show_sold_out' => true,
            'hide_sold_out' => false,
            'show_offer' => false,
            'offer_title' => 'عرض اليوم',
            'offer_text' => '',
            'offer_price' => '',
            'offer_product_id' => null,
            'hero_product_id' => null,
            'category_ids' => [],
            'hidden_product_ids' => [],
            'ticker' => '',
        ];
    }

    public function menu(Store $store): array
    {
        $row = Schema::hasTable('cashier_menus') ? DB::table('cashier_menus')->where('store_id', $store->id)->value('settings') : null;
        $saved = $row ? (array) json_decode((string) $row, true) : [];

        return array_replace($this->defaults($store), array_intersect_key($saved, $this->defaults($store)));
    }

    public function saveMenu(Store $store, array $data): array
    {
        $settings = array_replace($this->menu($store), array_intersect_key($data, $this->defaults($store)));
        DB::table('cashier_menus')->updateOrInsert(
            ['store_id' => $store->id],
            ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
        );

        return $settings;
    }

    // ------------------------------------------------------------ what the screen shows

    public function feed(Store $store, ?CashierDevice $device = null): array
    {
        $menu = $this->menu($store);
        $hidden = array_map('intval', (array) $menu['hidden_product_ids']);
        $products = Product::query()->where('store_id', $store->id)->whereNull('deleted_at')
            ->where('status', 'active')->where('is_available', true)
            ->whereNotIn('id', $hidden ?: [0])->orderBy('id')->get();
        $items = $products->map(fn (Product $p) => $this->productPayload($p))->filter(
            fn ($p) => ! ($menu['hide_sold_out'] && $p['sold_out'])
        )->values();

        $categoryNames = Category::query()->whereIn('id', $products->pluck('category_id')->filter()->unique())->pluck('name_ar', 'id');
        $order = array_map('intval', (array) $menu['category_ids']);
        $groups = $items->groupBy(fn ($p) => (int) ($p['category_id'] ?? 0));
        $keys = $groups->keys()->all();
        if ($order) {
            $keys = array_values(array_filter($order, fn ($id) => $groups->has($id)));
        }
        $categories = array_map(fn ($id) => [
            'id' => $id,
            'name' => $id ? ($categoryNames[$id] ?? 'منتجات') : 'منتجات',
            'products' => $groups->get($id)->values(),
        ], $keys);

        $publicWeb = rtrim((string) env('ZAD_PUBLIC_WEB_URL', 'https://zad-sync-sa.onrender.com'), '/');

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->name_ar,
                'logo_url' => $this->url($store->logo_path),
            ],
            'menu' => $menu,
            'categories' => $categories,
            'offer_product' => $menu['offer_product_id'] ? $items->firstWhere('id', (int) $menu['offer_product_id']) : null,
            'hero_product' => $menu['hero_product_id'] ? $items->firstWhere('id', (int) $menu['hero_product_id']) : ($items->first() ?: null),
            // Always shown: customers can order online later and Zad Sync delivers to their door.
            'zad' => [
                'store_url' => $publicWeb.'/stores/'.$store->id,
                'title' => 'اطلب من جوالك',
                'text' => 'امسح وافتح المتجر في زاد سينك — نوصّلك لين باب بيتك',
            ],
            'device' => $device ? ['id' => $device->id, 'name' => $device->name, 'kind' => $device->kind] : null,
            'enabled' => $this->enabled($store),
            'refreshed_at' => now()->toISOString(),
        ];
    }

    /** The receipt QR the menu screen shows for a minute after a customer pays. */
    public function receipt(CashierDevice $device): ?array
    {
        $settings = (array) ($device->settings ?? []);
        if (($settings['show_receipts'] ?? true) === false) {
            return null;
        }
        $from = array_map('intval', (array) ($settings['receipt_devices'] ?? []));
        $receipt = SaleReceipt::query()->where('store_id', $device->store_id)->where('status', 'paid')
            ->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM)
            ->where('issued_at', '>=', now()->subSeconds(self::RECEIPT_SECONDS))
            ->when($from && Schema::hasColumn('sale_receipts', 'cashier_device_id'), fn ($q) => $q->whereIn('cashier_device_id', $from))
            ->latest('id')->first();
        if ($receipt === null) {
            return null;
        }

        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'total' => (float) $receipt->total,
            'method_label' => SaleReceiptService::METHOD_LABELS[$receipt->payment_method] ?? $receipt->payment_method,
            'url' => $this->receipts->publicUrl($receipt),
            'store_name' => $receipt->store_name,
            'seconds_left' => max(0, self::RECEIPT_SECONDS - (int) $receipt->issued_at->diffInSeconds(now())),
        ];
    }

    public function productPayload(Product $p): array
    {
        $first = is_array($p->images) ? ($p->images[0] ?? null) : null;
        $soldOut = (bool) ($p->track_stock ?? false) && (int) $p->stock_quantity <= 0;

        return [
            'id' => $p->id,
            'category_id' => $p->category_id,
            'name' => $p->name_ar,
            'description' => $p->description_ar,
            'price' => (float) $p->price,
            'compare_at_price' => $p->compare_at_price === null ? null : (float) $p->compare_at_price,
            'image_url' => is_string($first) ? $this->url($first) : null,
            'sold_out' => $soldOut,
            'stock_left' => ($p->track_stock ?? false) ? max(0, (int) $p->stock_quantity) : null,
            'is_featured' => (bool) ($p->is_featured ?? false),
        ];
    }

    private function url(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path : Storage::disk('public')->url(ltrim($path, '/'));
    }

    public function devicePayload(CashierDevice $d): array
    {
        return [
            'id' => $d->id,
            'kind' => $d->kind,
            'name' => $d->name,
            'settings' => array_replace(['show_receipts' => true, 'receipt_devices' => []], (array) ($d->settings ?? [])),
            'online' => $d->last_seen_at !== null && $d->last_seen_at->gt(now()->subMinutes(2)),
            'last_seen_at' => $d->last_seen_at?->toISOString(),
        ];
    }
}
