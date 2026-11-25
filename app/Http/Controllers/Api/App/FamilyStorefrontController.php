<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\FamilyStorePlan;
use App\Models\Product;
use App\Models\Store;
use App\Services\App\FamilyStorefrontService;
use App\Services\App\FamilyStorePurchaseService;
use Cloudinary\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FamilyStorefrontController extends Controller
{
    public function __construct(
        private readonly FamilyStorefrontService $storefront,
        private readonly FamilyStorePurchaseService $purchases,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $this->familyStore($request);
        $control = \App\Models\PlatformControl::cachedValues()['family_subscriptions'] ?? [];

        return response()->json([
            'enabled' => (bool) ($control['enabled'] ?? false),
            'purchase_available' => $this->purchases->available(),
            'plans' => FamilyStorePlan::query()->where('is_visible', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $store = $this->familyStore($request);
        $entitlement = $this->storefront->entitlement($store->productiveFamily);

        return response()->json([
            'entitlement' => $entitlement,
            'store' => $this->storefront->publicStore($store),
            'draft_design' => $store->storefront_design ?? [],
            'logo_changes_used' => $store->family_logo_changes,
            'products' => Product::query()->where('store_id', $store->id)->where('status', 'active')
                ->get(['id', 'name_ar', 'description_ar', 'price', 'images', 'is_available']),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->familyStore($request);
        $data = $request->validate([
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'name_ar' => ['sometimes', 'string', 'min:2', 'max:100'],
            'theme' => ['sometimes', 'string', 'in:default,warm,modern,minimal'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font' => ['sometimes', 'string', 'in:system,cairo,tajawal,zad'],
            'product_card' => ['sometimes', 'string', 'in:standard,compact,editorial'],
        ]);

        DB::transaction(function () use ($store, $data): void {
            $store = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $entitlement = $this->storefront->entitlement($store->productiveFamily);
            $abilities = $entitlement['capabilities'];
            $map = ['theme' => 'themes', 'accent_color' => 'themes', 'font' => 'fonts', 'product_card' => 'product_cards'];
            foreach ($map as $field => $ability) {
                abort_if(array_key_exists($field, $data) && empty($abilities[$ability]), 403, 'هذه الميزة تتطلب ترقية باقة المتجر.');
            }
            $changes = array_intersect_key($data, $map);
            if ($changes) {
                $store->storefront_design = array_replace($store->storefront_design ?? [], $changes);
            }
            if (array_key_exists('description_ar', $data)) {
                $store->description_ar = $data['description_ar'];
            }
            if (isset($data['name_ar']) && $data['name_ar'] !== $store->name_ar) {
                abort_if(empty($abilities['rename_store']), 403, 'تغيير اسم المتجر متاح في الباقة الذهبية.');
                abort_if($store->family_name_changed_at?->gt(now()->subDays(30)), 422, 'يمكن تغيير الاسم مرة كل 30 يوماً.');
                $store->name_ar = trim($data['name_ar']);
                $store->family_name_changed_at = now();
            }
            $store->save();
        });

        return $this->show($request);
    }

    public function upload(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['logo', 'banner'], true), 404);
        $store = $this->familyStore($request);
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        $path = null;
        try {
            DB::transaction(function () use ($store, $request, $kind, &$path): void {
                $store = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
                $abilities = $this->storefront->entitlement($store->productiveFamily)['capabilities'];
                abort_if($kind === 'banner' && empty($abilities['banner']), 403, 'البنر يتطلب ترقية باقة المتجر.');
                abort_if($kind === 'logo' && $this->storefront->entitlement($store->productiveFamily)['plan'] === 'free' && $store->family_logo_changes >= 1, 403, 'تم استخدام تغيير الشعار المجاني.');
                $cloudinaryUrl = trim((string) config('filesystems.disks.public.cloudinary_url'));
                abort_if($cloudinaryUrl === '', 503, 'رفع صور المتجر غير متاح حالياً.');
                $prefix = trim((string) config('filesystems.disks.public.cloudinary_prefix', 'zad-sync'), '/');
                $result = (new Cloudinary($cloudinaryUrl))->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => $prefix.'/stores/'.$kind,
                    'resource_type' => 'image',
                    'unique_filename' => true,
                    'overwrite' => false,
                ]);
                $path = trim((string) ($result['secure_url'] ?? ''));
                abort_if($path === '', 503, 'تعذر رفع الصورة.');
                if ($kind === 'logo') {
                    $store->logo_path = $path;
                    $store->family_logo_changes++;
                } else {
                    $store->cover_path = $path;
                }
                $store->save();
            });
        } catch (Throwable $e) {
            if ($path) {
                // A database failure after uploading must not make an orphaned image public.
                try { Storage::disk('public')->delete($path); } catch (Throwable) {}
            }
            throw $e;
        }

        return $this->show($request);
    }

    private function familyStore(Request $request): Store
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->first();
        abort_unless($profile && in_array('productive_family', $profile->roles ?? [], true) && $profile->productive_family_id, 403, 'حساب أسرة منتجة مطلوب.');

        return Store::query()->with('productiveFamily')->where('productive_family_id', $profile->productive_family_id)->firstOrFail();
    }
}
