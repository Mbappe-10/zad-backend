<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverController extends Controller
{
    private const VEHICLE_TYPES = ['scooter', 'motorcycle', 'car'];

    private const REQUIRED_DOCUMENTS = [
        'scooter' => [
            'identity_photo',
            'scooter_front',
            'scooter_rear_box',
            'scooter_right',
            'scooter_left',
        ],
        'motorcycle' => [
            'identity_photo',
            'motorcycle_license',
            'vehicle_registration',
            'motorcycle_front',
            'motorcycle_rear_box',
            'motorcycle_right',
            'motorcycle_left',
        ],
        'car' => [
            'identity_photo',
            'driving_license',
            'vehicle_registration',
            'car_exterior',
            'cargo_interior',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Driver::query()
            ->with(['city', 'vehicle', 'documents'])
            ->withCount('documents');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('identity_number', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%")
                    ->orWhere('metadata->city', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('vehicle_type') && $request->input('vehicle_type') !== 'all') {
            $query->where('vehicle_type', $request->input('vehicle_type'));
        }

        if ($request->filled('city')) {
            $city = trim((string) $request->input('city'));
            $query->where(function ($query) use ($city): void {
                $query->where('metadata->city', 'like', "%{$city}%")
                    ->orWhereHas('city', function ($cityQuery) use ($city): void {
                        $cityQuery->where('name_ar', 'like', "%{$city}%")
                            ->orWhere('name_en', 'like', "%{$city}%");
                    });
            });
        }

        $drivers = $query->latest()->paginate(
            min(max((int) $request->input('per_page', 15), 1), 100),
        );
        $drivers->getCollection()->transform(
            fn (Driver $driver): array => $this->transformDriver($driver),
        );

        return response()->json($drivers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $driver = DB::transaction(function () use ($validated): Driver {
            $status = $validated['status'] ?? 'pending';
            $driver = Driver::query()->create([
                'code' => $this->generateCode(),
                'name' => trim($validated['name']),
                'phone' => trim($validated['phone']),
                'city_id' => $this->resolveCityId($validated['city']),
                'identity_number' => trim($validated['national_id']),
                'license_number' => null,
                'vehicle_type' => $validated['vehicle_type'],
                'plate_number' => $validated['vehicle_plate'] ?? null,
                'status' => $status,
                'application_status' => $this->applicationStatus($status),
                'is_online' => false,
                'active_orders_count' => 0,
                'rating' => 0,
                'metadata' => $this->metadata($validated),
                'submitted_at' => now(),
                'reviewed_at' => $status === 'active' ? now() : null,
            ]);

            $this->syncDocuments($driver, $validated['vehicle_type'], $validated['documents']);

            return $driver->fresh(['city', 'vehicle', 'documents']);
        });

        return response()->json([
            'message' => 'تمت إضافة المندوب بنجاح.',
            'data' => $this->transformDriver($driver),
        ], 201);
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        $validated = $this->validated($request, $driver);

        DB::transaction(function () use ($driver, $validated): void {
            $status = $validated['status'] ?? $driver->status;
            $metadata = array_merge(
                is_array($driver->metadata) ? $driver->metadata : [],
                $this->metadata($validated),
            );

            $driver->update([
                'name' => trim($validated['name']),
                'phone' => trim($validated['phone']),
                'city_id' => $this->resolveCityId($validated['city']),
                'identity_number' => trim($validated['national_id']),
                'license_number' => null,
                'vehicle_type' => $validated['vehicle_type'],
                'plate_number' => $validated['vehicle_plate'] ?? null,
                'status' => $status,
                'application_status' => $this->applicationStatus($status),
                'metadata' => $metadata,
                'reviewed_at' => $status === 'active' ? ($driver->reviewed_at ?? now()) : $driver->reviewed_at,
            ]);

            $this->syncDocuments($driver, $validated['vehicle_type'], $validated['documents']);
        });

        return response()->json([
            'message' => 'تم تحديث بيانات المندوب بنجاح.',
            'data' => $this->transformDriver($driver->fresh(['city', 'vehicle', 'documents'])),
        ]);
    }

    public function show(Driver $driver): JsonResponse
    {
        return response()->json([
            'data' => $this->transformDriver($driver->load(['city', 'vehicle', 'documents'])),
        ]);
    }

    public function changeStatus(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'active', 'offline', 'busy', 'suspended', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $requested = $validated['status'];
        $status = $requested === 'approved' ? 'active' : $requested;
        $approved = in_array($status, ['active', 'offline', 'busy'], true);

        $driver->forceFill([
            'status' => $status,
            'application_status' => $approved ? 'approved' : ($status === 'rejected' ? 'rejected' : 'pending'),
            'is_online' => $status === 'busy' ? $driver->is_online : false,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'rejection_reason' => $status === 'rejected'
                ? ($validated['rejection_reason'] ?? 'يرجى مراجعة بيانات طلب الانضمام.')
                : null,
        ])->save();

        return response()->json([
            'message' => 'تم تحديث حالة المندوب.',
            'data' => $this->transformDriver($driver->fresh(['city', 'vehicle', 'documents'])),
        ]);
    }

    public function destroy(Driver $driver): JsonResponse
    {
        if ((int) $driver->active_orders_count > 0) {
            return response()->json([
                'message' => 'لا يمكن حذف المندوب لوجود طلبات نشطة مسندة إليه.',
            ], 422);
        }

        $driver->update(['is_online' => false, 'status' => 'suspended']);
        $driver->delete();

        return response()->json(['message' => 'تم حذف المندوب بنجاح.']);
    }

    public function stats(): JsonResponse
    {
        $drivers = Driver::query();
        $all = Driver::query()->get();
        $driverIds = $all->pluck('id');
        $walletBalance = Wallet::query()
            ->where('owner_type', Driver::class)
            ->whereIn('owner_id', $driverIds)
            ->get(['available_balance', 'pending_balance'])
            ->sum(fn (Wallet $wallet): float =>
                (float) $wallet->available_balance + (float) $wallet->pending_balance
            );

        return response()->json([
            'total' => (clone $drivers)->count(),
            'active' => (clone $drivers)->whereIn('status', ['active', 'busy', 'offline'])->count(),
            'online' => (clone $drivers)->where('is_online', true)->count(),
            'busy' => (clone $drivers)->where(fn ($query) => $query->where('status', 'busy')->orWhere('active_orders_count', '>', 0))->count(),
            'pending' => (clone $drivers)->where('status', 'pending')->count(),
            'suspended' => (clone $drivers)->whereIn('status', ['suspended', 'rejected'])->count(),
            'deliveries' => (int) $all->sum(fn (Driver $driver): int => (int) data_get($driver->metadata, 'deliveries_count', 0)),
            'wallet_balance' => round((float) $walletBalance, 2),
            'average_rating' => round((float) ((clone $drivers)->avg('rating') ?? 0), 2),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $drivers = Driver::query()->with(['city', 'vehicle', 'documents'])->latest()->get();

        return response()->streamDownload(function () use ($drivers): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Code', 'Name', 'Phone', 'National ID', 'City', 'Vehicle Type', 'Model', 'Plate', 'Status']);

            foreach ($drivers as $driver) {
                $item = $this->transformDriver($driver);
                fputcsv($handle, [
                    $item['id'], $item['code'], $item['name'], $item['phone'],
                    $item['national_id'], $item['city'], $item['vehicle_type'],
                    $item['vehicle_model'], $item['vehicle_plate'], $item['status'],
                ]);
            }
            fclose($handle);
        }, 'delivery-drivers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validated(Request $request, ?Driver $driver = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:180'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('drivers', 'phone')->ignore($driver?->id)],
            'national_id' => ['required', 'string', 'max:30', Rule::unique('drivers', 'identity_number')->ignore($driver?->id)],
            'city' => ['required', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'offline', 'busy', 'suspended', 'rejected'])],
            'vehicle_type' => ['required', Rule::in(self::VEHICLE_TYPES)],
            'vehicle_model' => ['nullable', 'string', 'max:120'],
            'vehicle_plate' => ['nullable', 'string', 'max:30'],
            'has_delivery_box' => ['required', 'boolean'],
            'documents' => ['required', 'array'],
            'documents.*.path' => ['required', 'string', 'max:500'],
            'documents.*.url' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $type = (string) $request->input('vehicle_type');
            $model = trim((string) $request->input('vehicle_model'));
            $plate = trim((string) $request->input('vehicle_plate'));

            if (in_array($type, ['scooter', 'car'], true) && $model === '') {
                $validator->errors()->add('vehicle_model', 'موديل المركبة مطلوب لهذا النوع.');
            }
            if (in_array($type, ['motorcycle', 'car'], true) && $plate === '') {
                $validator->errors()->add('vehicle_plate', 'رقم اللوحة مطلوب لهذا النوع.');
            }
            if (in_array($type, ['scooter', 'motorcycle'], true) && ! $request->boolean('has_delivery_box')) {
                $validator->errors()->add('has_delivery_box', 'صندوق التوصيل إلزامي للسكوتر والدباب.');
            }

            $documents = (array) $request->input('documents', []);
            foreach (self::REQUIRED_DOCUMENTS[$type] ?? [] as $key) {
                if (blank(data_get($documents, "{$key}.path"))) {
                    $validator->errors()->add("documents.{$key}", "الصورة المطلوبة غير مرفوعة: {$key}.");
                }
            }
        });

        return $validator->validate();
    }

    private function metadata(array $validated): array
    {
        return [
            'city' => trim($validated['city']),
            'vehicle_model' => $validated['vehicle_model'] ?? null,
            'has_delivery_box' => (bool) ($validated['has_delivery_box'] ?? false),
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function syncDocuments(Driver $driver, string $vehicleType, array $documents): void
    {
        $allowed = self::REQUIRED_DOCUMENTS[$vehicleType] ?? [];

        DriverDocument::query()
            ->where('driver_id', $driver->id)
            ->whereNotIn('type', $allowed)
            ->delete();

        foreach ($allowed as $type) {
            $payload = $documents[$type] ?? [];
            $path = is_array($payload) ? ($payload['path'] ?? null) : $payload;

            if (blank($path)) {
                continue;
            }

            DriverDocument::query()->updateOrCreate(
                ['driver_id' => $driver->id, 'type' => $type],
                [
                    'path' => $path,
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ],
            );
        }

        $metadata = is_array($driver->metadata) ? $driver->metadata : [];
        $metadata['documents_count'] = count($allowed);
        $driver->update(['metadata' => $metadata]);
    }

    private function resolveCityId(string $cityName): ?int
    {
        $cityName = trim($cityName);
        if ($cityName === '') {
            return null;
        }

        return City::query()
            ->where('name_ar', $cityName)
            ->orWhere('name_en', $cityName)
            ->value('id');
    }

    private function transformDriver(Driver $driver): array
    {
        $driver->loadMissing(['city', 'vehicle', 'documents']);
        $metadata = is_array($driver->metadata) ? $driver->metadata : [];
        $wallet = Wallet::query()
            ->where('owner_type', Driver::class)
            ->where('owner_id', $driver->id)
            ->where('currency', 'SAR')
            ->first();

        return [
            'id' => $driver->id,
            'code' => $driver->code,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'city' => $metadata['city'] ?? $driver->city?->name_ar,
            'status' => $driver->status,
            'vehicle_type' => $driver->vehicle_type ?? 'scooter',
            'vehicle_model' => $metadata['vehicle_model'] ?? $driver->vehicle?->name,
            'vehicle_plate' => $driver->plate_number,
            'national_id' => $driver->identity_number,
            'has_delivery_box' => (bool) ($metadata['has_delivery_box'] ?? false),
            'documents_count' => $driver->documents->count(),
            'documents' => $driver->documents->map(fn (DriverDocument $document): array => [
                'id' => $document->id,
                'type' => $document->type,
                'path' => $document->path,
                'url' => $document->url ?: Storage::disk('public')->url($document->path),
                'status' => $document->status,
                'rejection_reason' => $document->rejection_reason,
            ])->values(),
            'wallet_balance' => round((float) ($wallet?->available_balance ?? 0) + (float) ($wallet?->pending_balance ?? 0), 2),
            'available_balance' => round((float) ($wallet?->available_balance ?? 0), 2),
            'pending_balance' => round((float) ($wallet?->pending_balance ?? 0), 2),
            'deliveries_count' => (int) ($metadata['deliveries_count'] ?? 0),
            'average_rating' => (float) ($driver->rating ?? 0),
            'notes' => $metadata['notes'] ?? null,
            'is_online' => (bool) $driver->is_online,
            'active_orders_count' => (int) ($driver->active_orders_count ?? 0),
            'created_at' => $driver->created_at?->toISOString(),
            'updated_at' => $driver->updated_at?->toISOString(),
        ];
    }

    private function applicationStatus(string $status): string
    {
        if (in_array($status, ['active', 'offline', 'busy'], true)) {
            return 'approved';
        }

        return $status === 'rejected' ? 'rejected' : 'pending';
    }

    private function generateCode(): string
    {
        do {
            $code = 'DRV-'.Str::upper(Str::random(8));
        } while (Driver::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
