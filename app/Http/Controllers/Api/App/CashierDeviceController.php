<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\App\CashierStaffService;
use App\Services\App\CashierZadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A cashier device (the shop iPad). It stays linked to the store and locked;
 * an employee picks her name and types her PIN to open it.
 */
class CashierDeviceController extends Controller
{
    public function __construct(
        private readonly CashierStaffService $staff,
        private readonly CashierZadService $cashier,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request, allowLocked: true);
        $store = $ctx->store;
        $enabled = $this->staff->enabled($store);

        return response()->json(['data' => [
            'store' => ['id' => $store->id, 'name' => $store->name_ar, 'logo_url' => $store->logo_url ?? null],
            'device' => ['id' => $ctx->device->id, 'name' => $ctx->device->name],
            'enabled' => $enabled,
            'personal' => $ctx->device->personal_staff_id !== null,
            'people' => $this->staff->members($store)->filter(fn ($s) => $s->active && ($enabled || $s->isOwner()))
                ->filter(fn ($s) => $ctx->device->personal_staff_id === null || (int) $s->id === (int) $ctx->device->personal_staff_id)
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'role_label' => $s->isOwner() ? 'المالكة' : (CashierStaffService::ROLES[$s->role]['label'] ?? $s->role),
                    'color' => $s->color,
                    'has_pin' => $s->pin_hash !== null,
                ])->values(),
            'unlocked' => $ctx->staff ? CashierStaffController::me($ctx) : null,
            'locked_until' => $ctx->device->locked_until?->isFuture() ? $ctx->device->locked_until->toISOString() : null,
        ]]);
    }

    /** "I work at a store": link this phone with the invite code, no Zad login needed. */
    public function join(Request $request): JsonResponse
    {
        abort_unless($this->staff->ready(), 503, 'الميزة قيد التجهيز.');
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $code = preg_match('/([A-Za-z2-9]{6})\s*$/', trim($data['code']), $m) ? $m[1] : $data['code'];
        [$device, $token, $member] = $this->staff->joinWithDevice($code, $data['device_name'] ?? 'جوال الموظفة');
        if (empty($data['device_name'])) {
            $device->forceFill(['name' => 'جوال '.$member->name])->save();
        }
        $store = \App\Models\Store::query()->find($member->store_id);

        return response()->json([
            'message' => 'أهلاً '.$member->name.'! صار جوالك كاشير '.$store?->name_ar.'.',
            'data' => ['token' => $token, 'store_name' => $store?->name_ar, 'staff_id' => $member->id, 'name' => $member->name],
        ], 201);
    }

    public function unlock(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request, allowLocked: true);
        $data = $request->validate([
            'staff_id' => ['required', 'integer'],
            'pin' => ['required', 'digits:4'],
        ]);
        [$member, $token] = $this->staff->unlock($ctx->device, (int) $data['staff_id'], $data['pin']);
        if (! $member->isOwner()) {
            abort_unless($this->staff->enabled($ctx->store), 403, 'كاشير برو غير مفعّل لهذا المتجر.');
        }

        return response()->json(['message' => 'أهلاً '.$member->name, 'data' => ['staff_token' => $token, 'name' => $member->name]]);
    }

    public function lock(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request, allowLocked: true);
        $this->staff->lock($ctx->device);

        return response()->json(['message' => 'تم قفل الكاشير.']);
    }
}
