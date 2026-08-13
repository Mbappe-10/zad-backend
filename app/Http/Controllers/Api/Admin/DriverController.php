<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Driver::query()->with(['city', 'vehicle']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('identity_number', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%")
                    ->orWhere('metadata->email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('vehicle_type') && $request->input('vehicle_type') !== 'all') {
            $query->where('vehicle_type', $request->input('vehicle_type'));
        }

        if ($request->filled('level') && $request->input('level') !== 'all') {
            $query->where('metadata->level', $request->input('level'));
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

        if ($request->filled('rating_from')) {
            $query->where('rating', '>=', (float) $request->input('rating_from'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction);

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $drivers = $query->paginate($perPage);

        $drivers->getCollection()->transform(
            fn (Driver $driver): array => $this->transformDriver($driver),
        );

        return response()->json($drivers);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات المندوب غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $driver = DB::transaction(function () use ($request): Driver {
            return Driver::query()->create([
                'code' => $this->generateCode(),
                'name' => trim((string) $request->input('name')),
                'phone' => trim((string) $request->input('phone')),
                'city_id' => $this->resolveCityId($request),
                'identity_number' => $request->input('national_id'),
                'license_number' => $request->input('license_number'),
                'vehicle_type' => $request->input('vehicle_type', 'scooter'),
                'plate_number' => $request->input('vehicle_plate'),
                'status' => $request->input('status', 'active'),
                'is_online' => false,
                'active_orders_count' => 0,
                'rating' => 0,
                'metadata' => $this->metadataFromRequest($request),
            ])->fresh(['city', 'vehicle']);
        });

        return response()->json([
            'message' => 'تمت إضافة المندوب بنجاح.',
            'data' => $this->transformDriver($driver),
        ], 201);
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true, $driver));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات المندوب غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $metadata = array_merge(
            is_array($driver->metadata) ? $driver->metadata : [],
            $this->metadataFromRequest($request, true),
        );

        $updates = [
            'name' => $request->input('name', $driver->name),
            'phone' => $request->input('phone', $driver->phone),
            'identity_number' => $request->exists('national_id') ? $request->input('national_id') : $driver->identity_number,
            'license_number' => $request->exists('license_number') ? $request->input('license_number') : $driver->license_number,
            'vehicle_type' => $request->input('vehicle_type', $driver->vehicle_type),
            'plate_number' => $request->exists('vehicle_plate') ? $request->input('vehicle_plate') : $driver->plate_number,
            'status' => $request->input('status', $driver->status),
            'metadata' => $metadata,
        ];

        if ($request->exists('city')) {
            $updates['city_id'] = $this->resolveCityId($request);
        }

        $driver->update($updates);

        return response()->json([
            'message' => 'تم تحديث بيانات المندوب بنجاح.',
            'data' => $this->transformDriver($driver->fresh(['city', 'vehicle'])),
        ]);
    }

    public function changeStatus(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'pending', 'active', 'offline', 'busy', 'suspended', 'rejected',
            ])],
        ]);

        $driver->update([
            'status' => $validated['status'],
            'is_online' => in_array($validated['status'], ['active', 'busy'], true)
                ? $driver->is_online
                : false,
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة المندوب بنجاح.',
            'data' => $this->transformDriver($driver->fresh(['city', 'vehicle'])),
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

        return response()->json([
            'total' => (clone $drivers)->count(),
            'active' => (clone $drivers)->whereIn('status', ['active', 'busy', 'offline'])->count(),
            'online' => (clone $drivers)->where('is_online', true)->count(),
            'busy' => (clone $drivers)->where(function ($query): void {
                $query->where('status', 'busy')->orWhere('active_orders_count', '>', 0);
            })->count(),
            'pending' => (clone $drivers)->where('status', 'pending')->count(),
            'suspended' => (clone $drivers)->whereIn('status', ['suspended', 'rejected'])->count(),
            'deliveries' => (int) $all->sum(fn (Driver $driver): int => (int) data_get($driver->metadata, 'deliveries_count', 0)),
            'wallet_balance' => (float) $all->sum(fn (Driver $driver): float => (float) data_get($driver->metadata, 'wallet_balance', 0)),
            'average_rating' => round((float) ((clone $drivers)->avg('rating') ?? 0), 2),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $drivers = Driver::query()->with(['city', 'vehicle'])->latest()->get();

        return response()->streamDownload(function () use ($drivers): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Code', 'Name', 'Phone', 'Email', 'City', 'Vehicle Type', 'Plate', 'Status']);

            foreach ($drivers as $driver) {
                $item = $this->transformDriver($driver);
                fputcsv($handle, [
                    $item['id'], $item['code'], $item['name'], $item['phone'],
                    $item['email'], $item['city'], $item['vehicle_type'],
                    $item['vehicle_plate'], $item['status'],
                ]);
            }

            fclose($handle);
        }, 'delivery-drivers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rules(bool $updating = false, ?Driver $driver = null): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:180'],
            'phone' => [$required, 'string', 'max:30', Rule::unique('drivers', 'phone')->ignore($driver?->id)],
            'email' => ['nullable', 'email', 'max:180'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'offline', 'busy', 'suspended', 'rejected'])],
            'level' => ['nullable', Rule::in(['bronze', 'silver', 'gold', 'platinum'])],
            'vehicle_type' => ['nullable', Rule::in(['scooter', 'motorcycle', 'car'])],
            'vehicle_model' => ['nullable', 'string', 'max:120'],
            'vehicle_plate' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:30', Rule::unique('drivers', 'identity_number')->ignore($driver?->id)],
            'license_number' => ['nullable', 'string', 'max:100'],
            'license_expires_at' => ['nullable', 'date'],
            'vehicle_registration_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function metadataFromRequest(Request $request, bool $onlyProvided = false): array
    {
        $fields = [
            'email', 'city', 'district', 'level', 'vehicle_model',
            'license_expires_at', 'vehicle_registration_expires_at', 'notes',
        ];

        $metadata = [];

        foreach ($fields as $field) {
            if (! $onlyProvided || $request->exists($field)) {
                $metadata[$field] = $request->input($field);
            }
        }

        return $metadata;
    }

    private function resolveCityId(Request $request): ?int
    {
        $cityName = trim((string) $request->input('city', ''));

        if ($cityName === '') {
            return null;
        }

        $city = City::query()
            ->where('name_ar', $cityName)
            ->orWhere('name_en', $cityName)
            ->first();

        return $city?->id;
    }

    private function transformDriver(Driver $driver): array
    {
        $driver->loadMissing(['city', 'vehicle']);
        $metadata = is_array($driver->metadata) ? $driver->metadata : [];

        $deliveries = (int) ($metadata['deliveries_count'] ?? 0);
        $completed = (int) ($metadata['completed_deliveries_count'] ?? 0);
        $cancelled = (int) ($metadata['cancelled_deliveries_count'] ?? 0);

        return [
            'id' => $driver->id,
            'code' => $driver->code,
            'name' => $driver->name,
            'email' => $metadata['email'] ?? null,
            'phone' => $driver->phone,
            'city' => $driver->city?->name_ar ?? ($metadata['city'] ?? null),
            'district' => $metadata['district'] ?? null,
            'status' => $driver->status,
            'level' => $metadata['level'] ?? 'bronze',
            'vehicle_type' => $driver->vehicle_type ?? 'scooter',
            'vehicle_model' => $metadata['vehicle_model'] ?? $driver->vehicle?->name ?? null,
            'vehicle_plate' => $driver->plate_number,
            'national_id' => $driver->identity_number,
            'license_number' => $driver->license_number,
            'license_expires_at' => $metadata['license_expires_at'] ?? null,
            'vehicle_registration_expires_at' => $metadata['vehicle_registration_expires_at'] ?? null,
            'documents_count' => (int) ($metadata['documents_count'] ?? 0),
            'wallet_balance' => (float) ($metadata['wallet_balance'] ?? 0),
            'total_earnings' => (float) ($metadata['total_earnings'] ?? 0),
            'deliveries_count' => $deliveries,
            'completed_deliveries_count' => $completed,
            'cancelled_deliveries_count' => $cancelled,
            'average_rating' => (float) ($driver->rating ?? 0),
            'acceptance_rate' => (float) ($metadata['acceptance_rate'] ?? 0),
            'completion_rate' => $deliveries > 0 ? round(($completed / $deliveries) * 100, 1) : 0,
            'online_minutes_today' => (int) ($metadata['online_minutes_today'] ?? 0),
            'last_latitude' => $driver->current_latitude !== null ? (float) $driver->current_latitude : null,
            'last_longitude' => $driver->current_longitude !== null ? (float) $driver->current_longitude : null,
            'last_seen_at' => $driver->location_updated_at?->toISOString(),
            'ai_score' => null,
            'ai_summary' => null,
            'notes' => $metadata['notes'] ?? null,
            'is_online' => (bool) $driver->is_online,
            'active_orders_count' => (int) ($driver->active_orders_count ?? 0),
            'created_at' => $driver->created_at?->toISOString(),
            'updated_at' => $driver->updated_at?->toISOString(),
        ];
    }

    private function generateCode(): string
    {
        do {
            $code = 'DRV-'.Str::upper(Str::random(8));
        } while (Driver::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
