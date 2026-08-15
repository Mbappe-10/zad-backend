<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductiveFamilyProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $profile || ! $profile->productive_family_id) {
            return response()->json([
                'message' => 'بيانات الأسرة المنتجة غير مكتملة.',
                'next_step' => 'complete_productive_family_profile',
            ], 404);
        }

        $family = ProductiveFamily::query()
            ->with('store')
            ->find($profile->productive_family_id);

        if (! $family || ! $family->store) {
            return response()->json([
                'message' => 'بيانات الأسرة أو المتجر غير مكتملة.',
                'next_step' => 'complete_productive_family_profile',
            ], 404);
        }

        return response()->json([
            'message' => 'تم تحميل بيانات الأسرة المنتجة.',
            'data' => $this->transformFamily($family),
            'next_step' => 'dashboard',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'owner_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^(?:\+966|00966|966|0)?5[0-9]{8}$/',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'store_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'city_id' => [
                'nullable',
                'integer',
                'exists:cities,id',
            ],
            'city' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'district' => [
                'nullable',
                'string',
                'max:100',
            ],
            'health_certificate_number' => [
                'nullable',
                'string',
                'max:100',
            ],
            'health_certificate_expires_at' => [
                'nullable',
                'date',
                'after_or_equal:today',
            ],
            'store_description_ar' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        [$family, $created] = DB::transaction(
            function () use ($request, $data): array {
                $profile = AppProfile::query()
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->first();

                if (! $profile) {
                    abort(
                        422,
                        'لم يتم العثور على الملف الخاص بحساب زاد.',
                    );
                }

                $roles = is_array($profile->roles)
                    ? $profile->roles
                    : [];

                if (! in_array('productive_family', $roles, true)) {
                    abort(
                        403,
                        'هذا الحساب غير مسجل كأسرة منتجة.',
                    );
                }

                if ($profile->productive_family_id) {
                    $existingFamily = ProductiveFamily::withTrashed()
                        ->find($profile->productive_family_id);

                    if ($existingFamily) {
                        $reactivated = $existingFamily->trashed();

                        if ($existingFamily->trashed()) {
                            $existingFamily->restore();
                        }

                        $existingMetadata = is_array($existingFamily->metadata)
                            ? $existingFamily->metadata
                            : [];

                        $existingFamily->update([
                            'owner_name' => trim($data['owner_name']),
                            'phone' => $this->normalizePhone($data['phone']),
                            'email' => $data['email'] ?? null,
                            'health_certificate_number' =>
                                $data['health_certificate_number'] ?? null,
                            'health_certificate_expires_at' =>
                                $data['health_certificate_expires_at'] ?? null,
                            'status' => 'pending',
                            'city_id' => $data['city_id'] ?? null,
                            'approved_by' => null,
                            'approved_at' => null,
                            'metadata' => array_merge($existingMetadata, [
                                'family_name' => trim($data['family_name']),
                                'store_name' => trim($data['store_name']),
                                'city' => trim($data['city']),
                                'district' => isset($data['district'])
                                    ? trim($data['district'])
                                    : null,
                            ]),
                        ]);

                        $existingStore = Store::withTrashed()
                            ->where('productive_family_id', $existingFamily->id)
                            ->first();

                        if ($existingStore) {
                            $reactivated = $reactivated || $existingStore->trashed();

                            if ($existingStore->trashed()) {
                                $existingStore->restore();
                            }

                            $existingStore->update([
                                'city_id' => $data['city_id'] ?? null,
                                'name_ar' => trim($data['store_name']),
                                'description_ar' =>
                                    $data['store_description_ar'] ?? null,
                                'status' => 'pending',
                                'is_open' => false,
                            ]);
                        } else {
                            $reactivated = true;
                            $existingStore = Store::query()->create([
                                'productive_family_id' => $existingFamily->id,
                                'city_id' => $data['city_id'] ?? null,
                                'name_ar' => trim($data['store_name']),
                                'name_en' => null,
                                'slug' => $this->generateStoreSlug(
                                    $data['store_name'],
                                    $existingFamily->id,
                                ),
                                'description_ar' =>
                                    $data['store_description_ar'] ?? null,
                                'description_en' => null,
                                'status' => 'pending',
                                'is_open' => false,
                                'rating' => 0,
                                'rating_count' => 0,
                                'working_hours' => [],
                            ]);
                        }

                        $profile->update([
                            'productive_family_id' => $existingFamily->id,
                            'active_mode' => 'productive_family',
                        ]);

                        $existingFamily->setRelation('store', $existingStore);

                        return [$existingFamily, $reactivated];
                    }
                }

                $family = ProductiveFamily::query()->create([
                    'code' => $this->generateFamilyCode(),
                    'owner_name' => trim($data['owner_name']),
                    'phone' => $this->normalizePhone($data['phone']),
                    'email' => $data['email'] ?? null,
                    'health_certificate_number' =>
                        $data['health_certificate_number'] ?? null,
                    'health_certificate_expires_at' =>
                        $data['health_certificate_expires_at'] ?? null,
                    'status' => 'pending',
                    'city_id' => $data['city_id'] ?? null,
                    'metadata' => [
                        'family_name' => trim($data['family_name']),
                        'store_name' => trim($data['store_name']),
                        'city' => trim($data['city']),
                        'district' => isset($data['district'])
                            ? trim($data['district'])
                            : null,
                        'subscription_plan' => 'free',
                        'wallet_balance' => 0,
                        'products_count' => 0,
                        'orders_count' => 0,
                        'average_rating' => 0,
                    ],
                ]);

                $store = Store::query()->create([
                    'productive_family_id' => $family->id,
                    'city_id' => $data['city_id'] ?? null,
                    'name_ar' => trim($data['store_name']),
                    'name_en' => null,
                    'slug' => $this->generateStoreSlug(
                        $data['store_name'],
                        $family->id,
                    ),
                    'description_ar' =>
                        $data['store_description_ar'] ?? null,
                    'description_en' => null,
                    'status' => 'pending',
                    'is_open' => false,
                    'rating' => 0,
                    'rating_count' => 0,
                    'working_hours' => [],
                ]);

                $profile->update([
                    'productive_family_id' => $family->id,
                    'active_mode' => 'productive_family',
                ]);

                $family->setRelation('store', $store);

                return [$family, true];
            },
        );

        return response()->json([
            'message' => $created
                ? 'تم حفظ بيانات الأسرة والمتجر وإرسالها للمراجعة.'
                : 'بيانات الأسرة والمتجر موجودة مسبقًا.',
            'data' => $this->transformFamily($family),
            'next_step' => 'dashboard',
        ], $created ? 201 : 200);
    }

    private function transformFamily(
        ProductiveFamily $family,
    ): array {
        $family->loadMissing('store');

        $metadata = is_array($family->metadata)
            ? $family->metadata
            : [];

        return [
            'family_id' => $family->id,
            'family_code' => $family->code,
            'family_name' => $metadata['family_name'] ?? '',
            'owner_name' => $family->owner_name,
            'phone' => $family->phone,
            'email' => $family->email,
            'city' => $metadata['city'] ?? '',
            'district' => $metadata['district'] ?? '',
            'status' => $family->status,
            'health_certificate_number' =>
                $family->health_certificate_number,
            'health_certificate_expires_at' =>
                $family->health_certificate_expires_at
                    ?->format('Y-m-d'),
            'store' => [
                'id' => $family->store?->id,
                'name' => $family->store?->name_ar,
                'slug' => $family->store?->slug,
                'status' => $family->store?->status,
                'is_open' => (bool) (
                    $family->store?->is_open ?? false
                ),
            ],
        ];
    }

    private function generateFamilyCode(): string
    {
        do {
            $code = 'PF-'.Str::upper(Str::random(8));
        } while (
            ProductiveFamily::withTrashed()
                ->where('code', $code)
                ->exists()
        );

        return $code;
    }

    private function generateStoreSlug(
        string $storeName,
        int $familyId,
    ): string {
        $base = Str::slug($storeName);

        if ($base === '') {
            $base = 'store-'.$familyId;
        }

        $slug = $base;
        $counter = 1;

        while (
            Store::withTrashed()
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace(
            '/[\s\-]+/',
            '',
            trim($phone),
        ) ?? trim($phone);

        if (str_starts_with($phone, '+966')) {
            return '0'.substr($phone, 4);
        }

        if (str_starts_with($phone, '00966')) {
            return '0'.substr($phone, 5);
        }

        if (str_starts_with($phone, '966')) {
            return '0'.substr($phone, 3);
        }

        if (
            strlen($phone) === 9
            && str_starts_with($phone, '5')
        ) {
            return '0'.$phone;
        }

        return $phone;
    }
}
