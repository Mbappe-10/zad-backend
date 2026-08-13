<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\City;
use App\Models\PlatformRecord;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = AppSetting::query()
            ->where('is_public', true)
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->mapWithKeys(function (AppSetting $setting): array {
                return [
                    $setting->key => $this->castSettingValue(
                        $setting->value,
                        $setting->type,
                    ),
                ];
            });

        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->limit(20)
            ->get([
                'id',
                'parent_id',
                'name_ar',
                'name_en',
                'slug',
                'image_path',
                'sort_order',
            ])
            ->map(function (Category $category): array {
                return [
                    'id' => $category->id,
                    'parent_id' => $category->parent_id,
                    'name_ar' => $category->name_ar,
                    'name_en' => $category->name_en,
                    'slug' => $category->slug,
                    'image_url' => $this->publicUrl($category->image_path),
                    'sort_order' => $category->sort_order,
                ];
            })
            ->values();

        $stores = DB::table('stores')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_open', true)
            ->orderByDesc('rating')
            ->orderByDesc('rating_count')
            ->limit(12)
            ->get([
                'id',
                'productive_family_id',
                'city_id',
                'name_ar',
                'name_en',
                'slug',
                'description_ar',
                'description_en',
                'logo_path',
                'cover_path',
                'rating',
                'rating_count',
                'working_hours',
            ])
            ->map(function (object $store): array {
                return [
                    'id' => $store->id,
                    'productive_family_id' => $store->productive_family_id,
                    'city_id' => $store->city_id,
                    'name_ar' => $store->name_ar,
                    'name_en' => $store->name_en,
                    'slug' => $store->slug,
                    'description_ar' => $store->description_ar,
                    'description_en' => $store->description_en,
                    'logo_url' => $this->publicUrl($store->logo_path),
                    'cover_url' => $this->publicUrl($store->cover_path),
                    'rating' => (float) $store->rating,
                    'rating_count' => (int) $store->rating_count,
                    'working_hours' => $this->decodeJson($store->working_hours),
                    'is_open' => true,
                ];
            })
            ->values();

        /*
         * كل منتج في زاد تابع لمتجر أسرة منتجة حقيقي.
         * نربط المنتجات بالمتاجر هنا حتى يرجع اسم المتجر مع المنتج،
         * ولا يضطر تطبيق Flutter إلى عرض اسم افتراضي غير موجود.
         */
        $products = Product::query()
            ->join('stores', 'stores.id', '=', 'products.store_id')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'active')
            ->where('products.is_available', true)
            ->whereNull('stores.deleted_at')
            ->where('stores.status', 'active')
            ->where('stores.is_open', true)
            ->whereNotNull('stores.name_ar')
            ->where('stores.name_ar', '<>', '')
            ->orderByDesc('products.updated_at')
            ->limit(20)
            ->get([
                'products.id',
                'products.store_id',
                'products.category_id',
                'products.sku',
                'products.name_ar',
                'products.name_en',
                'products.description_ar',
                'products.description_en',
                'products.price',
                'products.compare_at_price',
                'products.preparation_minutes',
                'products.images',
                'products.variants',
                'products.ingredients',
                'products.package_size',
                'stores.productive_family_id as store_productive_family_id',
                'stores.city_id as store_city_id',
                'stores.name_ar as store_name_ar',
                'stores.name_en as store_name_en',
                'stores.slug as store_slug',
                'stores.description_ar as store_description_ar',
                'stores.description_en as store_description_en',
                'stores.logo_path as store_logo_path',
                'stores.cover_path as store_cover_path',
                'stores.rating as store_rating',
                'stores.rating_count as store_rating_count',
                'stores.working_hours as store_working_hours',
                'stores.is_open as store_is_open',
            ])
            ->map(function (Product $product): array {
                $images = $this->normalizeImages($product->images);

                $store = [
                    'id' => (int) $product->store_id,
                    'productive_family_id' =>
                        (int) $product->store_productive_family_id,
                    'city_id' => (int) $product->store_city_id,
                    'name_ar' => $product->store_name_ar,
                    'name_en' => $product->store_name_en,
                    'slug' => $product->store_slug,
                    'description_ar' => $product->store_description_ar,
                    'description_en' => $product->store_description_en,
                    'logo_url' => $this->publicUrl(
                        $product->store_logo_path,
                    ),
                    'cover_url' => $this->publicUrl(
                        $product->store_cover_path,
                    ),
                    'rating' => (float) $product->store_rating,
                    'rating_count' => (int) $product->store_rating_count,
                    'working_hours' => $this->decodeJson(
                        $product->store_working_hours,
                    ),
                    'is_open' => (bool) $product->store_is_open,
                ];

                return [
                    'id' => $product->id,
                    'store_id' => $product->store_id,
                    'category_id' => $product->category_id,
                    'sku' => $product->sku,
                    'name_ar' => $product->name_ar,
                    'name_en' => $product->name_en,
                    'description_ar' => $product->description_ar,
                    'description_en' => $product->description_en,
                    'price' => (float) $product->price,
                    'compare_at_price' => $product->compare_at_price !== null
                        ? (float) $product->compare_at_price
                        : null,
                    'preparation_minutes' => (int) $product->preparation_minutes,
                    'package_size' => $product->package_size,
                    'images' => $images,
                    'primary_image_url' => $images[0] ?? null,
                    'variants' => $this->decodeJson($product->variants),
                    'ingredients' => $this->decodeJson($product->ingredients),
                    'is_available' => true,

                    // الربط المباشر الذي تستخدمه بطاقة المنتج في Flutter.
                    'store_name_ar' => $product->store_name_ar,
                    'store_name_en' => $product->store_name_en,
                    'store' => $store,
                ];
            })
            ->values();

        $banners = $this->homeBanners();

        $cities = City::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name_ar')
            ->get([
                'id',
                'code',
                'name_ar',
                'name_en',
                'delivery_base_fee',
            ])
            ->map(function (City $city): array {
                return [
                    'id' => $city->id,
                    'code' => $city->code,
                    'name_ar' => $city->name_ar,
                    'name_en' => $city->name_en,
                    'delivery_base_fee' => (float) $city->delivery_base_fee,
                ];
            })
            ->values();

        return response()->json([
            'app' => [
                'name' => 'زاد',
                'version' => '1.0.0',
                'guest_first' => true,
            ],
            'settings' => $settings,
            'categories' => $categories,
            'featured_stores' => $stores,
            'featured_products' => $products,
            'banners' => $banners,
            'cities' => $cities,
        ]);
    }

    /**
     * يحول سجلات الداشبورد العامة إلى بانرات آمنة للتطبيق.
     *
     * الداشبورد يحفظ الإعلانات والكوبونات والعروض داخل platform_records.
     * لا نرسل الميزانية أو التكلفة أو بيانات التدقيق إلى تطبيق العميل.
     */
    private function homeBanners(): array
    {
        return PlatformRecord::query()
            ->whereIn('resource', [
                'ads',
                'advertisements',
                'coupons',
                'offers',
            ])
            ->whereNull('deleted_at')
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->filter(
                fn (PlatformRecord $record): bool =>
                    $this->isVisiblePromotion($record),
            )
            ->sort(function (PlatformRecord $left, PlatformRecord $right): int {
                $leftPriority = $this->priorityRank(
                    data_get($left->payload, 'priority', 'normal'),
                );
                $rightPriority = $this->priorityRank(
                    data_get($right->payload, 'priority', 'normal'),
                );

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                $leftOrder = $this->integerFromPayload(
                    $left->payload,
                    ['displayOrder', 'display_order', 'order'],
                    999999,
                );
                $rightOrder = $this->integerFromPayload(
                    $right->payload,
                    ['displayOrder', 'display_order', 'order'],
                    999999,
                );

                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }

                return $right->id <=> $left->id;
            })
            ->take(20)
            ->map(fn (PlatformRecord $record): array =>
                $this->promotionPayload($record))
            ->values()
            ->all();
    }

    private function isVisiblePromotion(PlatformRecord $record): bool
    {
        $payload = is_array($record->payload) ? $record->payload : [];
        $status = strtolower((string) (
            $payload['status'] ?? $record->status ?? ''
        ));

        if (! in_array($status, ['active', 'scheduled'], true)) {
            return false;
        }

        if (! $this->booleanValue($payload['visible'] ?? true, true)) {
            return false;
        }

        $requiresApproval = $this->booleanValue(
            $payload['requiresApproval']
                ?? $payload['requires_approval']
                ?? false,
            false,
        );
        $approved = $this->booleanValue(
            $payload['approved'] ?? (! $requiresApproval),
            ! $requiresApproval,
        );

        if ($requiresApproval && ! $approved) {
            return false;
        }

        $startsAt = $this->dateFromPayload($payload, [
            'startAt',
            'startsAt',
            'startDate',
            'start_at',
            'starts_at',
            'start_date',
        ]);
        $endsAt = $this->dateFromPayload($payload, [
            'endAt',
            'endsAt',
            'endDate',
            'end_at',
            'ends_at',
            'end_date',
        ]);

        if ($startsAt !== null && $startsAt->isFuture()) {
            return false;
        }

        if ($endsAt !== null && $endsAt->isPast()) {
            return false;
        }

        $audience = strtolower((string) (
            $payload['audience']
                ?? $payload['targetAudience']
                ?? $payload['target_audience']
                ?? 'all'
        ));

        return in_array(
            $audience,
            ['', 'all', 'customer', 'customers', 'guests'],
            true,
        );
    }

    private function promotionPayload(PlatformRecord $record): array
    {
        $payload = is_array($record->payload) ? $record->payload : [];
        $kind = match ($record->resource) {
            'coupons' => 'coupon',
            'offers' => 'offer',
            default => 'advertisement',
        };

        $titleAr = $this->stringFromPayload(
            $payload,
            ['titleAr', 'title_ar', 'nameAr', 'name_ar', 'title', 'name'],
            match ($kind) {
                'coupon' => 'كوبون مميز من زاد',
                'offer' => 'عرض مميز من زاد',
                default => 'اكتشف جديد زاد',
            },
        );
        $titleEn = $this->stringFromPayload(
            $payload,
            ['titleEn', 'title_en', 'nameEn', 'name_en'],
            null,
        );
        $descriptionAr = $this->stringFromPayload(
            $payload,
            [
                'descriptionAr',
                'description_ar',
                'subtitleAr',
                'subtitle_ar',
                'description',
            ],
            null,
        );
        $descriptionEn = $this->stringFromPayload(
            $payload,
            ['descriptionEn', 'description_en', 'subtitleEn', 'subtitle_en'],
            null,
        );
        $mediaType = strtolower($this->stringFromPayload(
            $payload,
            ['mediaType', 'media_type'],
            'image',
        ) ?? 'image');
        $mediaPath = $this->stringFromPayload(
            $payload,
            [
                'mediaUrl',
                'media_url',
                'imageUrl',
                'image_url',
                'bannerUrl',
                'banner_url',
            ],
            null,
        );
        $duration = $this->integerFromPayload(
            $payload,
            ['duration', 'durationSeconds', 'duration_seconds'],
            8,
        );

        return [
            'id' => $record->id,
            'external_key' => $record->external_key,
            'kind' => $kind,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'description_ar' => $descriptionAr,
            'description_en' => $descriptionEn,
            'media_type' => $mediaType,
            'media_url' => $this->publicUrl($mediaPath),
            'duration_seconds' => min(max($duration, 5), 20),
            'button_text_ar' => $this->stringFromPayload(
                $payload,
                ['buttonTextAr', 'button_text_ar', 'buttonText', 'button_text'],
                match ($kind) {
                    'coupon' => 'استخدم الكوبون',
                    'offer' => 'عرض التفاصيل',
                    default => 'اكتشف الآن',
                },
            ),
            'coupon_code' => $this->stringFromPayload(
                $payload,
                ['code', 'couponCode', 'coupon_code'],
                null,
            ),
            'action_type' => $this->stringFromPayload(
                $payload,
                ['targetType', 'target_type', 'actionType', 'action_type'],
                'none',
            ),
            'action_value' => $payload['targetId']
                ?? $payload['target_id']
                ?? $payload['actionValue']
                ?? $payload['action_value']
                ?? null,
            'priority' => strtolower((string) (
                $payload['priority'] ?? 'normal'
            )),
            'display_order' => $this->integerFromPayload(
                $payload,
                ['displayOrder', 'display_order', 'order'],
                999999,
            ),
        ];
    }

    private function priorityRank(mixed $priority): int
    {
        return match (strtolower((string) $priority)) {
            'gold' => 0,
            'silver' => 1,
            default => 2,
        };
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function dateFromPayload(array $payload, array $keys): ?Carbon
    {
        $value = $this->firstPayloadValue($payload, $keys);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function integerFromPayload(
        array $payload,
        array $keys,
        int $default,
    ): int {
        $value = $this->firstPayloadValue($payload, $keys);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function stringFromPayload(
        array $payload,
        array $keys,
        ?string $default,
    ): ?string {
        $value = $this->firstPayloadValue($payload, $keys);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private function firstPayloadValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }

    private function castSettingValue(mixed $value, ?string $type): mixed
    {
        return match ($type) {
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'int' => (int) $value,
            'float', 'decimal', 'number' => (float) $value,
            'array', 'json', 'object' => $this->decodeJson($value),
            default => $value,
        };
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }

    private function normalizeImages(mixed $value): array
    {
        $images = $this->decodeJson($value);

        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->filter(
                fn (mixed $image): bool =>
                    is_string($image) && trim($image) !== '',
            )
            ->map(
                fn (string $image): string =>
                    $this->publicUrl($image) ?? $image,
            )
            ->values()
            ->all();
    }

    private function publicUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $cleanPath = trim($path);

        if (
            str_starts_with($cleanPath, 'http://') ||
            str_starts_with($cleanPath, 'https://')
        ) {
            $parsedPath = parse_url($cleanPath, PHP_URL_PATH);

            if (
                is_string($parsedPath) &&
                str_starts_with($parsedPath, '/storage/')
            ) {
                return $parsedPath;
            }

            return $cleanPath;
        }

        $cleanPath = ltrim($cleanPath, '/');

        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr(
                $cleanPath,
                strlen('storage/'),
            );
        }

        return '/storage/'.$cleanPath;
    }
}