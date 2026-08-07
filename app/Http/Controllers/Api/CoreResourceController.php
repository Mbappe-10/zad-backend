<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoreResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\DeliveryPricingRule;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Product\ProductAutoReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CoreResourceController extends Controller
{
    public function __construct(
        private readonly ProductAutoReviewService $productAutoReviewService,
    ) {
    }

    private const CONFIG = [
        'cities' => [City::class, ['code', 'name_ar', 'name_en'], ['code' => 'required|string|max:50|unique:cities,code', 'name_ar' => 'required|string|max:150', 'name_en' => 'required|string|max:150', 'is_active' => 'boolean', 'delivery_base_fee' => 'numeric|min:0', 'manager_id' => 'nullable|exists:users,id'], ['code', 'name_ar', 'name_en'], ['is_active', 'manager_id']],
        'delivery-zones' => [DeliveryZone::class, ['name_ar', 'name_en'], ['city_id' => 'required|exists:cities,id', 'name_ar' => 'required|string|max:150', 'name_en' => 'required|string|max:150', 'polygon' => 'nullable|array', 'extra_fee' => 'numeric|min:0', 'is_active' => 'boolean'], ['name_ar', 'name_en'], ['city_id', 'is_active']],
        'categories' => [Category::class, ['name_ar', 'name_en', 'slug'], ['parent_id' => 'nullable|exists:categories,id', 'name_ar' => 'required|string|max:150', 'name_en' => 'required|string|max:150', 'slug' => 'required|string|max:180|unique:categories,slug', 'image_path' => 'nullable|string|max:500', 'sort_order' => 'integer|min:0', 'is_active' => 'boolean'], ['name_ar', 'name_en', 'slug'], ['parent_id', 'is_active']],
        'productive-families' => [ProductiveFamily::class, ['code', 'owner_name', 'phone', 'email'], ['code' => 'required|string|max:50|unique:productive_families,code', 'owner_name' => 'required|string|max:180', 'phone' => 'required|string|max:30|unique:productive_families,phone', 'email' => 'nullable|email|max:180|unique:productive_families,email', 'health_certificate_number' => 'nullable|string|max:100', 'health_certificate_expires_at' => 'nullable|date', 'status' => 'required|string|max:40', 'city_id' => 'nullable|exists:cities,id', 'metadata' => 'nullable|array'], ['code', 'owner_name', 'phone', 'email'], ['status', 'city_id']],
        'stores' => [Store::class, ['name_ar', 'name_en', 'slug'], ['productive_family_id' => 'required|exists:productive_families,id', 'city_id' => 'nullable|exists:cities,id', 'name_ar' => 'required|string|max:180', 'name_en' => 'nullable|string|max:180', 'slug' => 'required|string|max:180|unique:stores,slug', 'description_ar' => 'nullable|string', 'description_en' => 'nullable|string', 'logo_path' => 'nullable|string|max:500', 'cover_path' => 'nullable|string|max:500', 'status' => 'required|string|max:40', 'is_open' => 'boolean', 'working_hours' => 'nullable|array'], ['name_ar', 'name_en', 'slug'], ['productive_family_id', 'city_id', 'status', 'is_open']],
        'products' => [Product::class, ['sku', 'name_ar', 'name_en'], ['store_id' => 'required|exists:stores,id', 'category_id' => 'nullable|exists:categories,id', 'sku' => 'nullable|string|max:100|unique:products,sku', 'name_ar' => 'required|string|max:180', 'name_en' => 'nullable|string|max:180', 'description_ar' => 'nullable|string', 'description_en' => 'nullable|string', 'price' => 'required|numeric|min:0', 'compare_at_price' => 'nullable|numeric|min:0', 'status' => 'required|string|max:40', 'is_available' => 'boolean', 'preparation_minutes' => 'integer|min:0', 'images' => 'nullable|array', 'variants' => 'nullable|array', 'ingredients' => 'nullable|array'], ['sku', 'name_ar', 'name_en'], ['store_id', 'category_id', 'status', 'is_available']],
        'customers' => [Customer::class, ['name', 'phone', 'email'], ['name' => 'required|string|max:180', 'phone' => 'required|string|max:30|unique:customers,phone', 'email' => 'nullable|email|max:180|unique:customers,email', 'status' => 'required|string|max:40'], ['name', 'phone', 'email'], ['status']],
        'drivers' => [Driver::class, ['code', 'name', 'phone', 'identity_number', 'plate_number'], ['user_id' => 'nullable|exists:users,id', 'city_id' => 'nullable|exists:cities,id', 'vehicle_id' => 'nullable|exists:vehicles,id', 'code' => 'required|string|max:50|unique:drivers,code', 'name' => 'required|string|max:180', 'phone' => 'required|string|max:30|unique:drivers,phone', 'identity_number' => 'nullable|string|max:30|unique:drivers,identity_number', 'license_number' => 'nullable|string|max:100', 'vehicle_type' => 'nullable|string|max:50', 'plate_number' => 'nullable|string|max:30', 'status' => 'required|string|max:40', 'is_online' => 'boolean', 'current_latitude' => 'nullable|numeric|between:-90,90', 'current_longitude' => 'nullable|numeric|between:-180,180', 'rating' => 'numeric|min:0|max:5'], ['code', 'name', 'phone', 'identity_number', 'plate_number'], ['city_id', 'vehicle_type', 'status']],
        'orders' => [Order::class, ['number', 'notes'], ['number' => 'required|string|max:80|unique:orders,number', 'customer_id' => 'nullable|exists:customers,id', 'store_id' => 'required|exists:stores,id', 'driver_id' => 'nullable|exists:drivers,id', 'city_id' => 'nullable|exists:cities,id', 'delivery_zone_id' => 'nullable|exists:delivery_zones,id', 'delivery_distance_km' => 'nullable|numeric|min:0', 'delivery_latitude' => 'nullable|numeric|between:-90,90', 'delivery_longitude' => 'nullable|numeric|between:-180,180', 'status' => 'required|string|max:40', 'payment_status' => 'required|string|max:40', 'subtotal' => 'numeric|min:0', 'delivery_fee' => 'numeric|min:0', 'discount' => 'numeric|min:0', 'tax' => 'numeric|min:0', 'total' => 'numeric|min:0', 'delivery_address' => 'nullable|array', 'notes' => 'nullable|string'], ['number', 'notes'], ['customer_id', 'store_id', 'driver_id', 'city_id', 'status', 'payment_status']],
        'order-items' => [OrderItem::class, ['product_name'], ['order_id' => 'required|exists:orders,id', 'product_id' => 'nullable|exists:products,id', 'product_name' => 'required|string|max:180', 'quantity' => 'required|integer|min:1', 'unit_price' => 'required|numeric|min:0', 'total' => 'required|numeric|min:0', 'options' => 'nullable|array'], ['product_name'], ['order_id', 'product_id']],
        'vehicles' => [Vehicle::class, ['name', 'type'], ['name' => 'required|string|max:150', 'type' => 'required|string|max:50', 'max_distance_km' => 'nullable|numeric|min:0', 'base_fee' => 'numeric|min:0', 'per_km_fee' => 'numeric|min:0', 'requires_box' => 'boolean', 'is_active' => 'boolean'], ['name', 'type'], ['type', 'is_active']],
        'delivery-pricing-rules' => [DeliveryPricingRule::class, ['name'], ['name' => 'required|string|max:150', 'city_id' => 'nullable|exists:cities,id', 'vehicle_id' => 'nullable|exists:vehicles,id', 'minimum_fee' => 'numeric|min:0', 'base_fee' => 'numeric|min:0', 'per_km_fee' => 'numeric|min:0', 'surge_multiplier' => 'numeric|min:1', 'priority' => 'integer|min:0', 'is_active' => 'boolean'], ['name'], ['city_id', 'vehicle_id', 'is_active']],
        'wallets' => [Wallet::class, ['currency'], ['owner_type' => 'required|string|max:180', 'owner_id' => 'required|integer|min:1', 'currency' => 'required|string|size:3', 'available_balance' => 'numeric', 'pending_balance' => 'numeric', 'is_frozen' => 'boolean'], ['currency'], ['owner_type', 'owner_id', 'currency', 'is_frozen']],
        'wallet-transactions' => [WalletTransaction::class, ['reference', 'description'], ['wallet_id' => 'required|exists:wallets,id', 'reference' => 'required|string|max:100|unique:wallet_transactions,reference', 'type' => 'required|string|max:40', 'amount' => 'required|numeric', 'balance_after' => 'required|numeric', 'status' => 'required|string|max:40', 'related_type' => 'nullable|string|max:180', 'related_id' => 'nullable|integer', 'description' => 'nullable|string'], ['reference', 'description'], ['wallet_id', 'type', 'status']],
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        [$model,,$rules,$searchable,$filterable] = $this->config($resource);
        $query = $model::query();
        $this->applySearch($query, $request, $searchable);
        foreach ($filterable as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        $sort = $request->string('sort', 'created_at')->toString();
        $direction = $request->string('direction', 'desc')->lower()->toString() === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, array_merge(['id', 'created_at', 'updated_at'], array_keys($rules)), true)) {
            $query->orderBy($sort, $direction);
        }
        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);

        return response()->json(CoreResource::collection($query->paginate($perPage))->response()->getData(true));
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        [$model,,$rules] = $this->config($resource);
        $data = $request->validate($rules);

        /*
         * المنتجات لا تعتمد على الحالة التي يرسلها المستخدم.
         * تبدأ المراجعة آليًا بعد الحفظ.
         */
        if ($resource === 'products') {
            $data['status'] = 'pending';
        }

        $record = DB::transaction(
            fn () => $model::create($data),
        );

        if ($resource === 'products' && $record instanceof Product) {
            $review = $this->reviewProduct($record);

            return response()->json([
                'message' => $this->productReviewMessage($review),
                'data' => new CoreResource($record->fresh()),
                'auto_review' => $review,
            ], 201);
        }

        return response()->json([
            'message' => 'تمت الإضافة بنجاح.',
            'data' => new CoreResource($record),
        ], 201);
    }

    public function show(string $resource, int $id): JsonResponse
    {
        [$model] = $this->config($resource);

        return response()->json(['data' => new CoreResource($model::query()->findOrFail($id))]);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        [$model,,$rules] = $this->config($resource);
        $record = $model::query()->findOrFail($id);
        $data = $request->validate(
            $this->updateRules(
                $rules,
                $record->getTable(),
                $id,
            ),
        );

        /*
         * عند تعديل المنتج يعاد فحصه آليًا.
         * لا نسمح للواجهة باعتماد المنتج مباشرة.
         */
        if ($resource === 'products') {
            unset($data['status']);
            $data['status'] = 'pending';
        }

        DB::transaction(
            fn () => $record->update($data),
        );

        if ($resource === 'products' && $record instanceof Product) {
            $review = $this->reviewProduct($record->fresh());

            return response()->json([
                'message' => $this->productReviewMessage($review),
                'data' => new CoreResource($record->fresh()),
                'auto_review' => $review,
            ]);
        }

        return response()->json([
            'message' => 'تم التحديث بنجاح.',
            'data' => new CoreResource($record->fresh()),
        ]);
    }

    /**
     * مراجعة المنتج آليًا وتطبيق النتيجة على حالة المنتج.
     *
     * نحافظ حاليًا على الحالات الموجودة في المشروع:
     * active  = اجتاز المراجعة ونُشر
     * draft   = يحتاج تعديل من الأسرة
     * pending = يحتاج مراجعة بشرية استثنائية
     *
     * @return array{
     *     status: string,
     *     approved: bool,
     *     score: int,
     *     reasons: array<int, string>
     * }
     */
    private function reviewProduct(Product $product): array
    {
        $product->refresh();

        $review = $this->productAutoReviewService->review(
            $product,
        );

        $systemStatus = match ($review['status']) {
            'published' => 'active',
            'needs_changes' => 'draft',
            'manual_review' => 'pending',
            default => 'pending',
        };

        if ($product->status !== $systemStatus) {
            $product->forceFill([
                'status' => $systemStatus,
            ])->save();
        }

        return [
            ...$review,
            'system_status' => $systemStatus,
        ];
    }

    /**
     * رسالة مبسطة للواجهة بعد المراجعة الآلية.
     */
    private function productReviewMessage(array $review): string
    {
        return match ($review['status'] ?? null) {
            'published' => 'تم حفظ المنتج واعتماده ونشره آليًا بنجاح.',
            'needs_changes' => 'تم حفظ المنتج، ويحتاج إلى بعض التعديلات قبل النشر.',
            'manual_review' => 'تم حفظ المنتج وتحويله للمراجعة الاستثنائية.',
            default => 'تم حفظ المنتج وإرساله للمراجعة الآلية.',
        };
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        [$model] = $this->config($resource);
        $model::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'تم الحذف بنجاح.']);
    }

    public function bulk(Request $request, string $resource): JsonResponse
    {
        [$model] = $this->config($resource);
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer', 'action' => 'required|in:delete,activate,deactivate,approve,reject,archive']);
        $query = $model::query()->whereIn('id', $data['ids']);
        $affected = $data['action'] === 'delete'
            ? $query->delete()
            : $query->update($this->bulkUpdate($resource, $data['action']));

        return response()->json(['message' => 'تم تنفيذ الإجراء الجماعي.', 'affected' => $affected]);
    }

    public function upload(Request $request, string $resource): JsonResponse
    {
        $this->config($resource);
        $data = $request->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv']);
        $path = $data['file']->store("uploads/{$resource}", 'public');

        return response()->json(['message' => 'تم رفع الملف بنجاح.', 'data' => ['path' => $path, 'url' => Storage::disk('public')->url($path)]], 201);
    }

    private function config(string $resource): array
    {
        abort_unless(isset(self::CONFIG[$resource]), 404, 'المورد غير معروف.');

        return self::CONFIG[$resource];
    }

    private function applySearch(Builder $query, Request $request, array $fields): void
    {
        if (! $request->filled('search')) {
            return;
        } $term = $request->string('search')->toString();
        $query->where(fn (Builder $q) => collect($fields)->each(fn ($field) => $q->orWhere($field, 'like', "%{$term}%")));
    }

    private function updateRules(array $rules, string $table, int $id): array
    {
        foreach ($rules as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', $rule);
            $parts = array_map(function ($part) use ($table, $field, $id) {
                if (is_string($part) && str_starts_with($part, 'unique:')) {
                    return Rule::unique($table, $field)->ignore($id);
                }

return $part;
            }, $parts);
            $rules[$field] = array_merge(['sometimes'], $parts);
        }

return $rules;
    }

    private function bulkUpdate(string $resource, string $action): array
    {
        if (in_array($resource, ['cities', 'delivery-zones', 'categories', 'vehicles', 'delivery-pricing-rules'], true)) {
            abort_unless(in_array($action, ['activate', 'deactivate'], true), 422, 'هذا الإجراء غير مدعوم لهذا المورد.');

            return ['is_active' => $action === 'activate'];
        }

        abort_if(in_array($resource, ['order-items', 'wallets', 'wallet-transactions'], true), 422, 'الإجراء الجماعي غير مدعوم لهذا المورد.');

        return ['status' => match ($action) {
            'activate' => 'active',
            'deactivate' => 'inactive',
            'approve' => 'approved',
            'reject' => 'rejected',
            'archive' => 'archived',
        }];
    }
}