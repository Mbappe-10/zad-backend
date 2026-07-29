<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductiveFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductiveFamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductiveFamily::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($query) use ($search) {
                $query->where('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('metadata->family_name', 'like', "%{$search}%")
                    ->orWhere('metadata->store_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $families = $query
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        $families->getCollection()->transform(
            fn (ProductiveFamily $family) => $this->transformFamily($family)
        );

        return response()->json($families);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'family_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'health_certificate_expires_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = ProductiveFamily::create([
            'code' => $this->generateCode(),
            'owner_name' => $request->owner_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'health_certificate_number' => $request->health_certificate_number,
            'health_certificate_expires_at' => $request->health_certificate_expires_at,
            'status' => $request->input('status', 'pending'),
            'city_id' => $request->city_id,
            'metadata' => $this->metadataFromRequest($request),
        ]);

        return response()->json([
            'message' => 'تمت إضافة الأسرة المنتجة بنجاح.',
            'data' => $this->transformFamily($family),
        ], 201);
    }

    public function update(
        Request $request,
        ProductiveFamily $family
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'family_name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'health_certificate_expires_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family->update([
            'owner_name' => $request->input('owner_name', $family->owner_name),
            'phone' => $request->input('phone', $family->phone),
            'email' => $request->input('email', $family->email),
            'health_certificate_number' => $request->input(
                'health_certificate_number',
                $family->health_certificate_number
            ),
            'health_certificate_expires_at' => $request->input(
                'health_certificate_expires_at',
                $family->health_certificate_expires_at
            ),
            'status' => $request->input('status', $family->status),
            'city_id' => $request->input('city_id', $family->city_id),
            'metadata' => array_merge(
                is_array($family->metadata) ? $family->metadata : [],
                $this->metadataFromRequest($request, true)
            ),
        ]);

        return response()->json([
            'message' => 'تم تحديث الأسرة المنتجة بنجاح.',
            'data' => $this->transformFamily($family->fresh()),
        ]);
    }

    public function changeStatus(
        Request $request,
        ProductiveFamily $family
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'حالة الأسرة مطلوبة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family->update([
            'status' => $request->status,
            'approved_at' => $request->status === 'approved' ? now() : null,
            'approved_by' => $request->status === 'approved'
                ? optional($request->user())->id
                : null,
        ]);

        return response()->json([
            'message' => 'تم تغيير حالة الأسرة بنجاح.',
            'data' => $this->transformFamily($family->fresh()),
        ]);
    }

    public function destroy(ProductiveFamily $family): JsonResponse
    {
        $family->delete();

        return response()->json([
            'message' => 'تم حذف الأسرة المنتجة بنجاح.',
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => ProductiveFamily::count(),
            'approved' => ProductiveFamily::where('status', 'approved')->count(),
            'pending' => ProductiveFamily::where('status', 'pending')->count(),
            'suspended' => ProductiveFamily::where('status', 'suspended')->count(),
            'rejected' => ProductiveFamily::where('status', 'rejected')->count(),
        ]);
    }

    public function export()
    {
        $families = ProductiveFamily::latest()->get();

        $csv = implode(',', [
            'ID',
            'Code',
            'Family Name',
            'Owner Name',
            'Phone',
            'Email',
            'Status',
        ])."\n";

        foreach ($families as $family) {
            $metadata = is_array($family->metadata) ? $family->metadata : [];

            $csv .= implode(',', [
                $family->id,
                $family->code,
                $this->escapeCsv($metadata['family_name'] ?? ''),
                $this->escapeCsv($family->owner_name),
                $this->escapeCsv($family->phone),
                $this->escapeCsv($family->email ?? ''),
                $family->status,
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="productive-families.csv"',
        ]);
    }

    private function metadataFromRequest(
        Request $request,
        bool $onlyProvided = false
    ): array {
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
        $metadata = is_array($family->metadata) ? $family->metadata : [];

        return [
            'id' => $family->id,
            'code' => $family->code,
            'family_name' => $metadata['family_name'] ?? '',
            'owner_name' => $family->owner_name,
            'phone' => $family->phone,
            'email' => $family->email,
            'store_name' => $metadata['store_name'] ?? '',
            'city' => $metadata['city'] ?? '',
            'district' => $metadata['district'] ?? '',
            'subscription_plan' => $metadata['subscription_plan'] ?? 'free',
            'national_id' => $metadata['national_id'] ?? '',
            'notes' => $metadata['notes'] ?? '',
            'wallet_balance' => (float) ($metadata['wallet_balance'] ?? 0),
            'products_count' => (int) ($metadata['products_count'] ?? 0),
            'orders_count' => (int) ($metadata['orders_count'] ?? 0),
            'average_rating' => (float) ($metadata['average_rating'] ?? 0),
            'ai_score' => (float) ($metadata['ai_score'] ?? 0),
            'status' => $family->status,
            'health_certificate_number' => $family->health_certificate_number,
            'health_certificate_expires_at' => optional($family->health_certificate_expires_at)?->format('Y-m-d'),
            'created_at' => optional($family->created_at)?->toISOString(),
            'updated_at' => optional($family->updated_at)?->toISOString(),
        ];
    }

    private function generateCode(): string
    {
        do {
            $code = 'PF-'.strtoupper(Str::random(8));
        } while (ProductiveFamily::where('code', $code)->exists());

        return $code;
    }

    private function escapeCsv(?string $value): string
    {
        return '"'.str_replace('"', '""', (string) $value).'"';
    }
}
