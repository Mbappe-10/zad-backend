<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Store::query()
            ->with(['productiveFamily:id,owner_name,health_certificate_expires_at,metadata', 'city'])
            ->withCount(['products', 'orders']);

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();

            $query->where(function ($query) use ($search): void {
                $query->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('productiveFamily', function ($familyQuery) use ($search): void {
                        $familyQuery->where('owner_name', 'like', "%{$search}%")
                            ->orWhere('metadata->family_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('plan') && $request->string('plan')->toString() !== 'all') {
            $query->whereHas('productiveFamily', fn ($familyQuery) =>
                $familyQuery->where('metadata->subscription_plan', $request->string('plan')->toString()),
            );
        }

        $stores = $query
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 12), 1), 100));

        $stores->getCollection()->transform(
            fn (Store $store): array => $this->transformStore($store),
        );

        return response()->json($stores);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات المتجر غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = ProductiveFamily::query()->findOrFail((int) $request->input('productive_family_id'));

        if ($family->store()->exists()) {
            return response()->json([
                'message' => 'هذه الأسرة مرتبطة بمتجر بالفعل.',
                'errors' => [
                    'productive_family_id' => ['لا يمكن إنشاء أكثر من متجر لنفس الأسرة.'],
                ],
            ], 422);
        }

        $store = DB::transaction(function () use ($request, $family): Store {
            return Store::query()->create($this->payload($request, null, $family));
        });

        return response()->json([
            'message' => 'تم إنشاء المتجر وربطه بالأسرة بنجاح.',
            'data' => $this->transformStore($store->fresh(['productiveFamily', 'city'])),
        ], 201);
    }

    public function show(Store $store): JsonResponse
    {
        return response()->json([
            'data' => $this->transformStore(
                $store->load(['productiveFamily', 'city'])->loadCount(['products', 'orders']),
            ),
        ]);
    }

    public function update(Request $request, Store $store): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true, $store));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات المتجر غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = $request->filled('productive_family_id')
            ? ProductiveFamily::query()->findOrFail((int) $request->input('productive_family_id'))
            : $store->productiveFamily;

        if (
            $family
            && Store::query()
                ->where('productive_family_id', $family->id)
                ->whereKeyNot($store->id)
                ->exists()
        ) {
            return response()->json([
                'message' => 'هذه الأسرة مرتبطة بمتجر آخر.',
            ], 422);
        }

        DB::transaction(function () use ($request, $store, $family): void {
            $store->update($this->payload($request, $store, $family));

            if ($family) {
                $metadata = is_array($family->metadata) ? $family->metadata : [];
                $metadata['store_name'] = $store->name_ar;
                $family->update(['metadata' => $metadata]);
            }
        });

        return response()->json([
            'message' => 'تم تحديث المتجر والأسرة المرتبطة به بنجاح.',
            'data' => $this->transformStore($store->fresh(['productiveFamily', 'city'])->loadCount(['products', 'orders'])),
        ]);
    }

    public function changeStatus(Request $request, Store $store): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:active,inactive,pending,approved,rejected,suspended,archived'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'حالة المتجر غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = $request->string('status')->toString();

        $store->update([
            'status' => $status,
            'is_open' => in_array($status, ['active', 'approved'], true) ? $store->is_open : false,
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة المتجر بنجاح.',
            'data' => $this->transformStore($store->fresh(['productiveFamily', 'city'])->loadCount(['products', 'orders'])),
        ]);
    }

    public function updateOpenStatus(Request $request, Store $store): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_open' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'حالة فتح المتجر غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! in_array($store->status, ['active', 'approved'], true) && $request->boolean('is_open')) {
            return response()->json([
                'message' => 'لا يمكن فتح متجر غير نشط أو غير معتمد.',
            ], 422);
        }

        $store->update(['is_open' => $request->boolean('is_open')]);

        return response()->json([
            'message' => $store->is_open ? 'تم فتح المتجر.' : 'تم إغلاق المتجر.',
            'data' => $this->transformStore($store->fresh(['productiveFamily', 'city'])->loadCount(['products', 'orders'])),
        ]);
    }

    public function destroy(Store $store): JsonResponse
    {
        $store->update([
            'status' => 'archived',
            'is_open' => false,
        ]);
        $store->delete();

        return response()->json([
            'message' => 'تمت أرشفة المتجر وحذفه حذفًا ناعمًا بنجاح.',
        ]);
    }

    private function rules(bool $updating = false, ?Store $store = null): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'productive_family_id' => [$required, 'integer', 'exists:productive_families,id'],
            'city_id' => ['nullable', 'integer'],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'cover_url' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'string', 'in:active,inactive,pending,approved,rejected,suspended,archived'],
            'is_open' => ['nullable', 'boolean'],
            'working_hours' => ['nullable', 'array'],
        ];
    }

    private function payload(Request $request, ?Store $store, ?ProductiveFamily $family): array
    {
        $nameAr = trim((string) $request->input('name_ar', $store?->name_ar ?? ''));
        $requestedSlug = trim((string) $request->input('slug', $store?->slug ?? ''));
        $slug = $requestedSlug !== '' ? Str::slug($requestedSlug) : Str::slug($nameAr);
        $slug = $this->uniqueSlug($slug !== '' ? $slug : 'store', $store?->id);

        $status = (string) $request->input('status', $store?->status ?? 'pending');
        $isOpen = $request->exists('is_open')
            ? $request->boolean('is_open')
            : (bool) ($store?->is_open ?? false);

        if (! in_array($status, ['active', 'approved'], true)) {
            $isOpen = false;
        }

        return [
            'productive_family_id' => $family?->id,
            'city_id' => $request->input('city_id', $store?->city_id ?? $family?->city_id),
            'name_ar' => $nameAr,
            'name_en' => $request->input('name_en', $store?->name_en),
            'slug' => $slug,
            'description_ar' => $request->input('description_ar', $store?->description_ar),
            'description_en' => $request->input('description_en', $store?->description_en),
            'logo_path' => $request->input('logo_path', $request->input('logo_url', $store?->logo_path)),
            'cover_path' => $request->input('cover_path', $request->input('cover_url', $store?->cover_path)),
            'status' => $status,
            'is_open' => $isOpen,
            'rating' => $store?->rating ?? 0,
            'rating_count' => $store?->rating_count ?? 0,
            'working_hours' => $request->input('working_hours', $store?->working_hours ?? []),
        ];
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        $counter = 1;

        while (
            Store::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function transformStore(Store $store): array
    {
        $store->loadMissing(['productiveFamily', 'city']);
        $metadata = is_array($store->productiveFamily?->metadata)
            ? $store->productiveFamily->metadata
            : [];

        return [
            'id' => $store->id,
            'productive_family_id' => $store->productive_family_id,
            'city_id' => $store->city_id,
            'name_ar' => $store->name_ar,
            'name_en' => $store->name_en,
            'slug' => $store->slug,
            'description_ar' => $store->description_ar,
            'description_en' => $store->description_en,
            'logo_url' => $this->fileUrl($store->logo_path),
            'cover_url' => $this->fileUrl($store->cover_path),
            'status' => $store->status,
            'plan' => $metadata['subscription_plan'] ?? 'free',
            'is_open' => (bool) $store->is_open,
            'is_featured' => (bool) ($metadata['is_featured'] ?? false),
            'rating' => (float) $store->rating,
            'rating_count' => (int) $store->rating_count,
            'products_count' => (int) ($store->products_count ?? $store->products()->count()),
            'orders_count' => (int) ($store->orders_count ?? $store->orders()->count()),
            'revenue' => (float) ($metadata['revenue'] ?? 0),
            'owner_name' => $store->productiveFamily?->owner_name,
            'family_name' => $metadata['family_name'] ?? null,
            'city_name' => $store->city?->name_ar ?? $metadata['city'] ?? null,
            'health_certificate_expires_at' => $store->productiveFamily?->health_certificate_expires_at?->format('Y-m-d'),
            'working_hours' => $store->working_hours ?? [],
            'created_at' => $store->created_at?->toISOString(),
            'updated_at' => $store->updated_at?->toISOString(),
        ];
    }

    private function fileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}