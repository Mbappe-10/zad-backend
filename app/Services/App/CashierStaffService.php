<?php

namespace App\Services\App;

use App\Models\AppProfile;
use App\Models\CashierDevice;
use App\Models\CashierShift;
use App\Models\CashierStaff;
use App\Models\CashierStaffInvite;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Models\User;
use App\Services\SaleReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cashier Zad Pro staff. The family owner adds employees with a PIN and permissions;
 * an employee either joins from her own Zad account with an invite code, or picks her
 * name on a cashier device the owner linked and types her PIN. The owner's Google
 * account is never shared, and employees never see the wallet or payouts.
 */
class CashierStaffService
{
    public const PERMISSIONS = [
        'sell' => 'البيع وإصدار الإيصال',
        'discount' => 'الخصم على البيعة',
        'refund' => 'المرتجع وإلغاء البيعة',
        'packaging' => 'تجهيز الطلبات والتغليف',
        'inventory' => 'متابعة المخزون والكميات',
        'reports' => 'الأرباح والتقارير',
    ];

    public const ROLES = [
        'cashier' => ['label' => 'كاشير', 'permissions' => ['sell']],
        'packaging' => ['label' => 'تغليف', 'permissions' => ['packaging']],
        'inventory' => ['label' => 'متابعة مخزون', 'permissions' => ['inventory']],
        'supervisor' => ['label' => 'مشرفة', 'permissions' => ['sell', 'discount', 'refund', 'packaging', 'inventory', 'reports']],
    ];

    public const COLORS = ['#FF7900', '#1D7A45', '#5B3FD1', '#0E7C86', '#D6457A', '#C99A2E', '#3B5BDB', '#8C5A3C'];

    /** Cashier devices (iPads) a store may link; menu/kitchen screens have their own limit. */
    public const CASHIER_DEVICE_LIMIT = 3;

    private const INVITE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const STAFF_SESSION_HOURS = 16;

    public function __construct(private readonly CashierZadService $cashier) {}

    public function ready(): bool
    {
        return Schema::hasTable('cashier_staff');
    }

    public function enabled(Store $store): bool
    {
        return ! empty($this->cashier->entitlement($store->productiveFamily)['features']['assistants']);
    }

    public function limit(): int
    {
        return $this->cashier->settings()['staff_limit'];
    }

    // ---------------------------------------------------------------- who is it

    /** Resolves the store and the person behind a cashier request. */
    public function resolve(Request $request, bool $allowLocked = false): CashierContext
    {
        if ($cached = $request->attributes->get('cashier_context')) {
            return $cached;
        }

        if ($membership = $request->route('membership')) {
            $context = $this->fromMembership($request->user(), (int) $membership);
        } elseif ($request->header('X-Cashier-Device')) {
            $context = $this->fromDevice($request, $allowLocked);
        } else {
            $context = $this->fromOwner($request->user());
        }
        $request->attributes->set('cashier_context', $context);

        return $context;
    }

    private function fromOwner(?User $user): CashierContext
    {
        abort_unless($user !== null, 401);
        $profile = AppProfile::query()->where('user_id', $user->id)->first();
        abort_unless($profile?->productive_family_id !== null, 403, 'هذا الحساب غير مرتبط بأسرة منتجة.');
        $store = Store::query()->with('productiveFamily')->where('productive_family_id', $profile->productive_family_id)->first();
        abort_unless($store !== null, 404, 'لم يتم العثور على متجر الأسرة.');

        return new CashierContext($store, $this->ready() ? $this->ownerRow($store) : null);
    }

    private function fromMembership(?User $user, int $staffId): CashierContext
    {
        abort_unless($user !== null && $this->ready(), 401);
        $staff = CashierStaff::query()->whereKey($staffId)->where('user_id', $user->id)
            ->where('active', true)->whereNull('removed_at')->first();
        abort_unless($staff !== null, 403, 'ما عاد عندك صلاحية على هذا المتجر.');
        $store = Store::query()->with('productiveFamily')->findOrFail($staff->store_id);
        abort_unless($this->enabled($store), 403, 'كاشير برو غير مفعّل لهذا المتجر.');
        $staff->forceFill(['last_seen_at' => now()])->saveQuietly();

        return new CashierContext($store, $staff);
    }

    private function fromDevice(Request $request, bool $allowLocked): CashierContext
    {
        abort_unless($this->ready(), 401);
        $device = CashierDevice::query()->where('token_hash', hash('sha256', (string) $request->header('X-Cashier-Device')))
            ->whereNull('revoked_at')->first();
        abort_unless($device !== null, 401, 'هذا الجهاز غير مربوط بأي متجر.');
        $store = Store::query()->with('productiveFamily')->findOrFail($device->store_id);
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        $staff = null;
        $token = (string) $request->header('X-Cashier-Staff');
        if ($token !== '' && $device->staff_id && $device->staff_token_hash
            && hash_equals($device->staff_token_hash, hash('sha256', $token))
            && $device->unlocked_at?->gt(now()->subHours(self::STAFF_SESSION_HOURS))) {
            $staff = CashierStaff::query()->whereKey($device->staff_id)->where('store_id', $store->id)
                ->where('active', true)->whereNull('removed_at')->first();
        }
        abort_unless($staff !== null || $allowLocked, 423, 'الكاشير مقفل. اختاري اسمك واكتبي رمزك.');
        if ($staff !== null) {
            abort_unless($staff->isOwner() || $this->enabled($store), 403, 'كاشير برو غير مفعّل لهذا المتجر.');
        }

        return new CashierContext($store, $staff, $device);
    }

    // ---------------------------------------------------------------- staff

    /** The owner appears on the lock screen too, with all permissions. */
    public function ownerRow(Store $store): CashierStaff
    {
        return CashierStaff::query()->firstOrCreate(
            ['store_id' => $store->id, 'role' => 'owner'],
            [
                'name' => $store->productiveFamily?->owner_name ?: 'المالكة',
                'permissions' => array_keys(self::PERMISSIONS),
                'color' => '#102B4E',
                'active' => true,
            ],
        );
    }

    public function members(Store $store)
    {
        return CashierStaff::query()->where('store_id', $store->id)->whereNull('removed_at')
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('id')->get();
    }

    public function employeeCount(Store $store): int
    {
        return CashierStaff::query()->where('store_id', $store->id)->where('role', '!=', 'owner')->whereNull('removed_at')->count();
    }

    public function create(Store $store, array $data): array
    {
        return DB::transaction(function () use ($store, $data): array {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            abort_if($this->employeeCount($store) >= $this->limit(), 422,
                'وصلتِ للحد: '.$this->limit().' موظفين في كاشير برو.');
            $staff = CashierStaff::query()->create([
                'store_id' => $store->id,
                'name' => $data['name'],
                'role' => $data['role'],
                'permissions' => $this->cleanPermissions($data['permissions'] ?? self::ROLES[$data['role']]['permissions']),
                'max_discount_percent' => (int) ($data['max_discount_percent'] ?? 0),
                'pin_hash' => Hash::make($data['pin']),
                'color' => self::COLORS[$this->employeeCount($store) % count(self::COLORS)],
                'active' => true,
            ]);

            return [$staff, $this->newInvite($staff)];
        });
    }

    public function update(CashierStaff $staff, array $data): CashierStaff
    {
        $fill = array_intersect_key($data, array_flip(['name', 'active', 'max_discount_percent', 'rating', 'rating_note']));
        if (! $staff->isOwner() && isset($data['role'])) {
            $fill['role'] = $data['role'];
        }
        if (! $staff->isOwner() && array_key_exists('permissions', $data)) {
            $fill['permissions'] = $this->cleanPermissions((array) $data['permissions']);
        }
        if (! empty($data['pin'])) {
            $fill['pin_hash'] = Hash::make($data['pin']);
        }
        $staff->fill($fill)->save();
        if (($fill['active'] ?? true) === false || isset($fill['pin_hash'])) {
            $this->endSessions($staff);
        }

        return $staff->fresh();
    }

    public function remove(CashierStaff $staff): void
    {
        abort_if($staff->isOwner(), 422, 'ما تقدرين تحذفين حساب المالكة.');
        $staff->forceFill(['active' => false, 'removed_at' => now(), 'user_id' => null])->save();
        CashierDevice::query()->where('personal_staff_id', $staff->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        CashierStaffInvite::query()->where('staff_id', $staff->id)->whereNull('used_at')->delete();
        $this->endSessions($staff);
    }

    private function endSessions(CashierStaff $staff): void
    {
        CashierDevice::query()->where('staff_id', $staff->id)
            ->update(['staff_id' => null, 'staff_token_hash' => null, 'unlocked_at' => null]);
    }

    private function cleanPermissions(array $permissions): array
    {
        return array_values(array_intersect(array_keys(self::PERMISSIONS), $permissions));
    }

    // ---------------------------------------------------------------- invites

    public function newInvite(CashierStaff $staff): CashierStaffInvite
    {
        CashierStaffInvite::query()->where('staff_id', $staff->id)->whereNull('used_at')->delete();
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::INVITE_ALPHABET[random_int(0, strlen(self::INVITE_ALPHABET) - 1)];
            }
        } while (CashierStaffInvite::query()->where('code', $code)->exists());

        return CashierStaffInvite::query()->create([
            'staff_id' => $staff->id,
            'code' => $code,
            'expires_at' => now()->addDay(),
        ]);
    }

    /** An employee scans the invite from "My account" and links her own Zad account. */
    public function join(User $user, string $code): CashierStaff
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return DB::transaction(function () use ($user, $code): CashierStaff {
            $invite = CashierStaffInvite::query()->where('code', $code)->lockForUpdate()->first();
            abort_unless($invite !== null && $invite->used_at === null && $invite->expires_at->isFuture(), 422,
                'الكود غير صحيح أو انتهى. اطلبي كود جديد من صاحبة المتجر.');
            $staff = CashierStaff::query()->whereKey($invite->staff_id)->whereNull('removed_at')->where('active', true)->first();
            abort_unless($staff !== null, 422, 'هذه الدعوة ما عادت صالحة.');
            $ownerFamily = AppProfile::query()->where('user_id', $user->id)->value('productive_family_id');
            $store = Store::query()->find($staff->store_id);
            abort_if($ownerFamily !== null && (int) $ownerFamily === (int) $store?->productive_family_id, 422, 'هذا متجرك أنتِ.');
            CashierStaff::query()->where('store_id', $staff->store_id)->where('user_id', $user->id)
                ->whereKeyNot($staff->id)->update(['user_id' => null]);
            $staff->forceFill(['user_id' => $user->id, 'last_seen_at' => now()])->save();
            $invite->forceFill(['used_at' => now(), 'used_by_user_id' => $user->id])->save();

            return $staff;
        });
    }

    public function memberships(User $user)
    {
        if (! $this->ready()) {
            return collect();
        }

        return CashierStaff::query()->where('user_id', $user->id)->where('active', true)->whereNull('removed_at')->get()
            ->map(function (CashierStaff $s): array {
                $store = Store::query()->with('productiveFamily')->find($s->store_id);

                return [
                    'id' => $s->id,
                    'store_id' => $s->store_id,
                    'store_name' => $store?->name_ar,
                    'store_logo' => $store?->logo_url ?? null,
                    'role' => $s->role,
                    'role_label' => self::ROLES[$s->role]['label'] ?? $s->role,
                    'permissions' => $s->permissions ?? [],
                    'active' => $store !== null && $this->enabled($store),
                ];
            })->values();
    }

    // ---------------------------------------------------------------- devices

    /** @return array{0: CashierDevice, 1: string} the device and its token, shown once. */
    public function linkDevice(Store $store, string $kind, string $name, ?int $userId): array
    {
        $count = CashierDevice::query()->where('store_id', $store->id)->where('kind', $kind)
            ->whereNull('personal_staff_id')->whereNull('revoked_at')->count();
        $limit = $kind === 'cashier' ? self::CASHIER_DEVICE_LIMIT : $this->cashier->settings()['screens_limit'];
        abort_if($count >= $limit, 422, "وصلتِ للحد: {$limit} أجهزة.");
        $token = Str::random(64);
        $device = CashierDevice::query()->create([
            'store_id' => $store->id,
            'kind' => $kind,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'created_by_user_id' => $userId,
            'last_seen_at' => now(),
        ]);

        return [$device, $token];
    }

    /**
     * The employee types or scans her invite on her own phone (no Zad login needed).
     * The phone becomes her personal cashier: only she appears on it, with her PIN.
     *
     * @return array{0: CashierDevice, 1: string, 2: CashierStaff}
     */
    public function joinWithDevice(string $code, string $name): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return DB::transaction(function () use ($code, $name): array {
            $invite = CashierStaffInvite::query()->where('code', $code)->lockForUpdate()->first();
            abort_unless($invite !== null && $invite->used_at === null && $invite->expires_at->isFuture(), 422,
                'الكود غير صحيح أو انتهى. اطلب كود جديد من صاحبة المتجر.');
            $staff = CashierStaff::query()->whereKey($invite->staff_id)->whereNull('removed_at')->where('active', true)->first();
            abort_unless($staff !== null, 422, 'هذه الدعوة ما عادت صالحة.');
            CashierDevice::query()->where('personal_staff_id', $staff->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = Str::random(64);
            $device = CashierDevice::query()->create([
                'store_id' => $staff->store_id,
                'kind' => 'cashier',
                'name' => $name,
                'personal_staff_id' => $staff->id,
                'token_hash' => hash('sha256', $token),
                'last_seen_at' => now(),
            ]);
            $invite->forceFill(['used_at' => now()])->save();

            return [$device, $token, $staff];
        });
    }

    /** @return string the employee's session token on this device */
    public function unlock(CashierDevice $device, int $staffId, string $pin): array
    {
        abort_if($device->locked_until?->isFuture(), 429, 'محاولات كثيرة. جرّبي بعد دقائق.');
        abort_if($device->personal_staff_id !== null && (int) $device->personal_staff_id !== $staffId, 403, 'هذا الجوال لموظفة ثانية.');
        $staff = CashierStaff::query()->whereKey($staffId)->where('store_id', $device->store_id)
            ->where('active', true)->whereNull('removed_at')->first();
        abort_unless($staff !== null, 404, 'الموظفة غير موجودة.');
        abort_unless($staff->pin_hash !== null, 422, $staff->isOwner()
            ? 'حددي رمزك من جوالك: كاشير زاد ← الموظفين.' : 'ما لها رمز. المالكة تحدده من جوالها.');

        if (! Hash::check($pin, $staff->pin_hash)) {
            $failed = $device->failed_pins + 1;
            $device->forceFill([
                'failed_pins' => $failed >= 5 ? 0 : $failed,
                'locked_until' => $failed >= 5 ? now()->addMinutes(5) : null,
            ])->save();
            abort(422, $failed >= 5 ? 'محاولات كثيرة. الكاشير مقفل ٥ دقائق.' : 'الرمز غير صحيح.');
        }
        $token = Str::random(48);
        $device->forceFill([
            'staff_id' => $staff->id,
            'staff_token_hash' => hash('sha256', $token),
            'unlocked_at' => now(),
            'failed_pins' => 0,
            'locked_until' => null,
        ])->save();
        $staff->forceFill(['last_seen_at' => now()])->saveQuietly();

        return [$staff, $token];
    }

    public function lock(CashierDevice $device): void
    {
        $device->forceFill(['staff_id' => null, 'staff_token_hash' => null, 'unlocked_at' => null])->save();
    }

    // ---------------------------------------------------------------- shifts

    public function openShift(CashierContext $ctx): ?CashierShift
    {
        if (! $this->ready() || $ctx->staff === null) {
            return null;
        }

        return CashierShift::query()->where('store_id', $ctx->store->id)->where('staff_id', $ctx->staff->id)
            ->whereNull('closed_at')->latest('id')->first();
    }

    public function startShift(CashierContext $ctx, float $openingCash): CashierShift
    {
        abort_if($ctx->staff === null, 422, 'الورديات تحتاج تفعيل الموظفين.');
        abort_if($this->openShift($ctx) !== null, 422, 'عندك وردية مفتوحة. اقفليها أول.');

        return CashierShift::query()->create([
            'store_id' => $ctx->store->id,
            'staff_id' => $ctx->staff->id,
            'device_id' => $ctx->device?->id,
            'staff_name' => $ctx->staff->name,
            'opening_cash' => round(max(0, $openingCash), 2),
            'opened_at' => now(),
        ]);
    }

    public function summary(CashierShift $shift): array
    {
        $receipts = SaleReceipt::query()->where('cashier_shift_id', $shift->id)->where('status', 'paid')
            ->get(['payment_method', 'total', 'discount']);
        $methods = [];
        foreach ($receipts->groupBy('payment_method') as $method => $group) {
            $methods[] = [
                'method' => $method,
                'label' => SaleReceiptService::METHOD_LABELS[$method] ?? $method,
                'count' => $group->count(),
                'total' => round((float) $group->sum('total'), 2),
            ];
        }
        $cash = round((float) $receipts->where('payment_method', 'cash')->sum('total'), 2);

        return [
            'sales_count' => $receipts->count(),
            'sales_total' => round((float) $receipts->sum('total'), 2),
            'discounts' => round((float) $receipts->sum('discount'), 2),
            'cash_sales' => $cash,
            'expected_cash' => round((float) $shift->opening_cash + $cash, 2),
            'methods' => $methods,
        ];
    }

    public function closeShift(CashierContext $ctx, float $counted, ?string $note): CashierShift
    {
        $shift = $this->openShift($ctx);
        abort_unless($shift !== null, 422, 'ما عندك وردية مفتوحة.');
        $summary = $this->summary($shift);
        $shift->forceFill([
            'closed_at' => now(),
            'counted_cash' => round(max(0, $counted), 2),
            'expected_cash' => $summary['expected_cash'],
            'summary' => $summary + ['difference' => round($counted - $summary['expected_cash'], 2)],
            'note' => $note,
        ])->save();
        if (! $ctx->isOwner()) {
            $this->notifyOwner($ctx->store, $shift);
        }

        return $shift;
    }

    public function shiftPayload(CashierShift $shift): array
    {
        $summary = $shift->closed_at ? ($shift->summary ?? []) : $this->summary($shift);

        return [
            'id' => $shift->id,
            'staff_id' => $shift->staff_id,
            'staff_name' => $shift->staff_name,
            'opening_cash' => (float) $shift->opening_cash,
            'opened_at' => $shift->opened_at?->toISOString(),
            'closed_at' => $shift->closed_at?->toISOString(),
            'counted_cash' => $shift->counted_cash === null ? null : (float) $shift->counted_cash,
            'expected_cash' => (float) ($summary['expected_cash'] ?? $shift->opening_cash),
            'difference' => $summary['difference'] ?? null,
            'sales_count' => $summary['sales_count'] ?? 0,
            'sales_total' => $summary['sales_total'] ?? 0,
            'methods' => $summary['methods'] ?? [],
            'note' => $shift->note,
        ];
    }

    private function notifyOwner(Store $store, CashierShift $shift): void
    {
        try {
            $diff = (float) ($shift->summary['difference'] ?? 0);
            $body = 'المبيعات '.number_format((float) ($shift->summary['sales_total'] ?? 0), 2).' ر.س • الكاش المتوقع '
                .number_format((float) $shift->expected_cash, 2).' والمعدود '.number_format((float) $shift->counted_cash, 2)
                .($diff == 0.0 ? ' • مطابق' : ' • الفرق '.number_format($diff, 2));
            $userIds = AppProfile::query()->where('productive_family_id', $store->productive_family_id)->pluck('user_id');
            foreach ($userIds as $userId) {
                DB::table('in_app_notifications')->insertOrIgnore([
                    'user_id' => $userId,
                    'type' => 'transactional',
                    'locale' => 'ar',
                    'title' => 'تقرير وردية '.$shift->staff_name,
                    'body' => $body,
                    'data' => json_encode(['kind' => 'cashier_shift', 'shift_id' => $shift->id]),
                    'idempotency_key' => hash('sha256', 'cashier_shift|'.$shift->id.'|'.$userId),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function staffPayload(CashierStaff $s): array
    {
        $invite = CashierStaffInvite::query()->where('staff_id', $s->id)->whereNull('used_at')
            ->where('expires_at', '>', now())->latest('id')->first();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'role' => $s->role,
            'role_label' => $s->isOwner() ? 'المالكة' : (self::ROLES[$s->role]['label'] ?? $s->role),
            'permissions' => $s->permissions ?? [],
            'max_discount_percent' => (int) $s->max_discount_percent,
            'color' => $s->color,
            'has_pin' => $s->pin_hash !== null,
            'linked_account' => $s->user_id !== null,
            'active' => (bool) $s->active,
            'last_seen_at' => $s->last_seen_at?->toISOString(),
            'rating' => $s->rating ?? null,
            'rating_note' => $s->rating_note ?? null,
            'invite' => $invite ? ['code' => $invite->code, 'expires_at' => $invite->expires_at->toISOString()] : null,
        ];
    }
}
