<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductiveFamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductiveFamily::query()->with('store');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();

            $query->where(function ($query) use ($search): void {
                $query->where('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('metadata->family_name', 'like', "%{$search}%")
                    ->orWhere('metadata->store_name', 'like', "%{$search}%")
                    ->orWhereHas('store', function ($storeQuery) use ($search): void {
                        $storeQuery->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('subscription_plan') && $request->string('subscription_plan')->toString() !== 'all') {
            $query->where('metadata->subscription_plan', $request->string('subscription_plan')->toString());
        }

        if ($request->filled('city')) {
            $query->where('metadata->city', 'like', '%'.$request->string('city')->trim()->toString().'%');
        }

        if ($request->filled('rating_from')) {
            $query->where('metadata->average_rating', '>=', (float) $request->input('rating_from'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $families = $query
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        $families->getCollection()->transform(
            fn (ProductiveFamily $family): array => $this->transformFamily($family),
        );

        return response()->json($families);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = DB::transaction(function () use ($request): ProductiveFamily {
            $family = ProductiveFamily::query()->create([
                'code' => $this->generateCode(),
                'owner_name' => $request->string('owner_name')->trim()->toString(),
                'phone' => $request->string('phone')->trim()->toString(),
                'email' => $request->filled('email') ? $request->string('email')->trim()->toString() : null,
                'health_certificate_number' => $request->input('health_certificate_number'),
                'health_certificate_expires_at' => $request->input('health_certificate_expires_at'),
                'status' => $request->input('status', 'pending'),
                'city_id' => $request->input('city_id'),
                'metadata' => $this->metadataFromRequest($request),
            ]);

            $this->syncStore($family, $request);

            return $family->fresh(['store']);
        });

        return response()->json([
            'message' => 'تمت إضافة الأسرة وإنشاء متجرها المرتبط بنجاح.',
            'data' => $this->transformFamily($family),
        ], 201);
    }

    public function update(Request $request, ProductiveFamily $family): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = DB::transaction(function () use ($request, $family): ProductiveFamily {
            $metadata = array_merge(
                is_array($family->metadata) ? $family->metadata : [],
                $this->metadataFromRequest($request, true),
            );

            $family->update([
                'owner_name' => $request->input('owner_name', $family->owner_name),
                'phone' => $request->input('phone', $family->phone),
                'email' => $request->exists('email') ? $request->input('email') : $family->email,
                'health_certificate_number' => $request->input('health_certificate_number', $family->health_certificate_number),
                'health_certificate_expires_at' => $request->input('health_certificate_expires_at', $family->health_certificate_expires_at),
                'status' => $request->input('status', $family->status),
                'city_id' => $request->input('city_id', $family->city_id),
                'metadata' => $metadata,
            ]);

            $this->syncStore($family->fresh(), $request);

            return $family->fresh(['store']);
        });

        return response()->json([
            'message' => 'تم تحديث الأسرة والمتجر المرتبط بها بنجاح.',
            'data' => $this->transformFamily($family),
        ]);
    }

    public function changeStatus(Request $request, ProductiveFamily $family): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:pending,active,approved,suspended,rejected,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'حالة الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = DB::transaction(function () use ($request, $family): ProductiveFamily {
            $status = $request->string('status')->toString();

            $family->update([
                'status' => $status,
                'approved_at' => in_array($status, ['active', 'approved'], true) ? now() : null,
                'approved_by' => in_array($status, ['active', 'approved'], true)
                    ? $request->user()?->id
                    : null,
            ]);

            $this->syncStore($family->fresh(), $request);

            return $family->fresh(['store']);
        });

        return response()->json([
            'message' => 'تم تغيير حالة الأسرة ومزامنة متجرها بنجاح.',
            'data' => $this->transformFamily($family),
        ]);
    }

    public function destroy(ProductiveFamily $family): JsonResponse
    {
        DB::transaction(function () use ($family): void {
            $family->store()->update([
                'status' => 'suspended',
                'is_open' => false,
            ]);

            $family->delete();
        });

        return response()->json([
            'message' => 'تم حذف الأسرة وتعليق متجرها المرتبط بنجاح.',
        ]);
    }

    public function stats(): JsonResponse
    {
        $families = ProductiveFamily::query();

        return response()->json([
            'total' => (clone $families)->count(),
            'active' => (clone $families)->whereIn('status', ['active', 'approved'])->count(),
            'approved' => (clone $families)->whereIn('status', ['active', 'approved'])->count(),
            'pending' => (clone $families)->where('status', 'pending')->count(),
            'suspended' => (clone $families)->whereIn('status', ['suspended', 'inactive'])->count(),
            'rejected' => (clone $families)->where('status', 'rejected')->count(),
            'products' => Store::query()->withCount('products')->get()->sum('products_count'),
            'orders' => Store::query()->withCount('orders')->get()->sum('orders_count'),
            'wallet_balance' => (float) ProductiveFamily::query()->get()->sum(
                fn (ProductiveFamily $family): float => (float) data_get($family->metadata, 'wallet_balance', 0),
            ),
            'average_rating' => round((float) Store::query()->avg('rating'), 2),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $families = ProductiveFamily::query()->with('store')->latest()->get();

        return response()->streamDownload(function () use ($families): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Code', 'Family Name', 'Store Name', 'Owner Name', 'Phone', 'Email', 'Status']);

            foreach ($families as $family) {
                $metadata = is_array($family->metadata) ? $family->metadata : [];
                fputcsv($handle, [
                    $family->id,
                    $family->code,
                    $metadata['family_name'] ?? '',
                    $family->store?->name_ar ?? ($metadata['store_name'] ?? ''),
                    $family->owner_name,
                    $family->phone,
                    $family->email,
                    $family->status,
                ]);
            }

            fclose($handle);
        }, 'productive-families.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'family_name' => [$required, 'string', 'max:255'],
            'owner_name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:pending,active,approved,suspended,rejected,inactive'],
            'city_id' => ['nullable', 'integer'],
            'health_certificate_number' => ['nullable', 'string', 'max:255'],
            'health_certificate_expires_at' => ['nullable', 'date'],
        ];
    }

    private function syncStore(ProductiveFamily $family, Request $request): Store
    {
        $metadata = is_array($family->metadata) ? $family->metadata : [];
        $familyName = trim((string) ($metadata['family_name'] ?? $family->owner_name));
        $storeName = trim((string) ($request->input('store_name') ?: ($metadata['store_name'] ?? $familyName)));
        $storeName = $storeName !== '' ? $storeName : $familyName;

        $storeStatus = $this->storeStatusForFamily($family->status);
        $isOpen = in_array($storeStatus, ['active', 'approved'], true);

        $slug = $family->store?->slug ?: $this->uniqueStoreSlug($storeName, $family->id);

        return Store::query()->updateOrCreate(
            ['productive_family_id' => $family->id],
            [
                'city_id' => $family->city_id,
                'name_ar' => $storeName,
                'name_en' => $request->input('store_name_en', $family->store?->name_en),
                'slug' => $slug,
                'description_ar' => $request->input('store_description_ar', $family->store?->description_ar),
                'description_en' => $request->input('store_description_en', $family->store?->description_en),
                'status' => $storeStatus,
                'is_open' => $isOpen,
                'rating' => $family->store?->rating ?? 0,
                'rating_count' => $family->store?->rating_count ?? 0,
                'working_hours' => $family->store?->working_hours ?? [],
            ],
        );
    }

    private function storeStatusForFamily(string $familyStatus): string
    {
        return match ($familyStatus) {
            'active', 'approved' => 'active',
            'pending' => 'pending',
            'rejected' => 'rejected',
            default => 'suspended',
        };
    }

    private function uniqueStoreSlug(string $name, int $familyId): string
    {
        $base = Str::slug($name) ?: 'store-'.$familyId;
        $slug = $base;
        $counter = 1;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function metadataFromRequest(Request $request, bool $onlyProvided = false): array
    {
        $fields = [
            'family_name',
            'store_name',
            'city',
            'district',
            'subscription_plan',
            'national_id',
            'notes',
            'wallet_balance',
            'products_count',
            'orders_count',
            'average_rating',
            'ai_score',
        ];

        $metadata = [];

        foreach ($fields as $field) {
            if (! $onlyProvided || $request->exists($field)) {
                $metadata[$field] = $request->input($field);
            }
        }

        return $metadata;
    }

    private function transformFamily(ProductiveFamily $family): array
    {
        $family->loadMissing('store');
        $metadata = is_array($family->metadata) ? $family->metadata : [];

        return [
            'id' => $family->id,
            'code' => $family->code,
            'family_name' => $metadata['family_name'] ?? '',
            'owner_name' => $family->owner_name,
            'phone' => $family->phone,
            'email' => $family->email,
            'store_id' => $family->store?->id,
            'store_name' => $family->store?->name_ar ?? ($metadata['store_name'] ?? ''),
            'store_slug' => $family->store?->slug,
            'store_status' => $family->store?->status,
            'store_is_open' => (bool) ($family->store?->is_open ?? false),
            'city' => $metadata['city'] ?? '',
            'district' => $metadata['district'] ?? '',
            'subscription_plan' => $metadata['subscription_plan'] ?? 'free',
            'national_id' => $metadata['national_id'] ?? '',
            'notes' => $metadata['notes'] ?? '',
            'wallet_balance' => (float) ($metadata['wallet_balance'] ?? 0),
            'products_count' => $family->store?->products()->count() ?? (int) ($metadata['products_count'] ?? 0),
            'orders_count' => $family->store?->orders()->count() ?? (int) ($metadata['orders_count'] ?? 0),
            'average_rating' => (float) ($family->store?->rating ?? $metadata['average_rating'] ?? 0),
            'ai_score' => (float) ($metadata['ai_score'] ?? 0),
            'status' => $family->status,
            'health_certificate_number' => $family->health_certificate_number,
            'health_certificate_expires_at' => $family->health_certificate_expires_at?->format('Y-m-d'),
            'created_at' => $family->created_at?->toISOString(),
            'updated_at' => $family->updated_at?->toISOString(),
        ];
    }

    private function generateCode(): string
    {
        do {
            $code = 'PF-'.Str::upper(Str::random(8));
        } while (ProductiveFamily::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}