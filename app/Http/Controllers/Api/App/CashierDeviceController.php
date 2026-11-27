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
            'people' => $this->staff->members($store)->filter(fn ($s) => $s->active && ($enabled || $s->isOwner()))
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
