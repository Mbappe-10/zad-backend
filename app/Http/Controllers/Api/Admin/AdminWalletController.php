<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\OrderSettlement;
use App\Models\Payout;
use App\Models\ProductiveFamily;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FinancialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminWalletController extends Controller
{
    private const FAMILY_OWNER_TYPES = [
        ProductiveFamily::class,
        'family',
        'productive_family',
    ];

    private const DRIVER_OWNER_TYPES = [
        Driver::class,
        'driver',
        'courier',
    ];

    public function __construct(
        private readonly FinancialService $financialService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'owner_type' => [
                'nullable',
                Rule::in(['family', 'driver', 'platform', 'customer']),
            ],
            'status' => [
                'nullable',
                Rule::in(['active', 'frozen', 'suspended', 'closed']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Wallet::query()
            ->withMax('transactions as last_transaction_at', 'created_at')
            ->withCount([
                'payouts as open_payouts_count' => fn (Builder $query) =>
                    $query->whereIn('status', [
                        'pending',
                        'approved',
                        'processing',
                    ]),
            ])
            ->withSum([
                'transactions as total_earned' => fn (Builder $query) =>
                    $query
                        ->where('status', 'completed')
                        ->whereIn('type', [
                            'order_settlement_family',
                            'order_settlement_driver',
                            'manual_credit',
                            'credit',
                            'refund',
                        ]),
            ], 'amount')
            ->withSum([
                'payouts as total_withdrawn' => fn (Builder $query) =>
                    $query->whereIn('status', ['paid', 'completed']),
            ], 'amount');

        $this->applyOwnerTypeFilter($query, $data['owner_type'] ?? null);
        $this->applyStatusFilter($query, $data['status'] ?? null);
        $this->applySearch($query, trim((string) ($data['search'] ?? '')));

        $paginator = $query
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        $owners = $this->ownersFor($paginator->getCollection());

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (Wallet $wallet): array =>
                    $this->walletPayload($wallet, $owners)),
        );

        return response()->json([
            ...$paginator->toArray(),
            'summary' => $this->overviewPayload(),
        ]);
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'data' => $this->overviewPayload(),
        ]);
    }

    public function show(Wallet $wallet): JsonResponse
    {
        $wallet = Wallet::query()
            ->withMax('transactions as last_transaction_at', 'created_at')
            ->withCount([
                'payouts as open_payouts_count' => fn (Builder $query) =>
                    $query->whereIn('status', [
                        'pending',
                        'approved',
                        'processing',
                    ]),
            ])
            ->withSum([
                'transactions as total_earned' => fn (Builder $query) =>
                    $query
                        ->where('status', 'completed')
                        ->whereIn('type', [
                            'order_settlement_family',
                            'order_settlement_driver',
                            'manual_credit',
                            'credit',
                            'refund',
                        ]),
            ], 'amount')
            ->withSum([
                'payouts as total_withdrawn' => fn (Builder $query) =>
                    $query->whereIn('status', ['paid', 'completed']),
            ], 'amount')
            ->findOrFail($wallet->id);

        $owners = $this->ownersFor(collect([$wallet]));

        return response()->json([
            'data' => $this->walletPayload($wallet, $owners),
        ]);
    }

    public function transactions(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            $wallet->transactions()
                ->latest('id')
                ->paginate((int) ($data['per_page'] ?? 50)),
        );
    }

    public function adjust(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference' => [
                'nullable',
                'string',
                'max:100',
                'unique:wallet_transactions,reference',
            ],
        ]);

        $transaction = $this->financialService->adjustWallet(
            $wallet,
            $data['direction'],
            (float) $data['amount'],
            trim($data['reason']),
            filled($data['reference'] ?? null)
                ? trim((string) $data['reference'])
                : null,
            $request->user()?->id,
        );

        return response()->json([
            'message' => $data['direction'] === 'credit'
                ? 'تمت إضافة الرصيد وتسجيل الحركة المالية.'
                : 'تم خصم الرصيد وتسجيل الحركة المالية.',
            'data' => $transaction,
        ], 201);
    }

    public function updateStatus(Request $request, Wallet $wallet): JsonResponse
    {
        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['active', 'frozen', 'suspended', 'closed']),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $status = $data['status'];

        if ($status === 'closed') {
            if (
                (float) $wallet->available_balance !== 0.0 ||
                (float) $wallet->pending_balance !== 0.0
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'لا يمكن إغلاق محفظة تحتوي على رصيد متاح أو معلق.',
                    ],
                ]);
            }

            $hasOpenPayout = $wallet->payouts()
                ->whereIn('status', ['pending', 'approved', 'processing'])
                ->exists();

            if ($hasOpenPayout) {
                throw ValidationException::withMessages([
                    'status' => [
                        'لا يمكن إغلاق المحفظة قبل إنهاء طلبات السحب المفتوحة.',
                    ],
                ]);
            }
        }

        $previousStatus = $wallet->status ?: (
            $wallet->is_frozen ? 'frozen' : 'active'
        );

        if ($previousStatus === $status) {
            return response()->json([
                'message' => 'حالة المحفظة محدثة مسبقًا.',
                'data' => $wallet,
            ]);
        }

        $wallet->forceFill([
            'status' => $status,
            'is_frozen' => $status !== 'active',
        ])->save();

        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'reference' => 'WST-'.Str::upper(Str::random(16)),
            'type' => 'wallet_status',
            'amount' => 0,
            'balance_after' => (float) $wallet->available_balance,
            'status' => 'completed',
            'description' => sprintf(
                'تغيير حالة المحفظة من %s إلى %s: %s',
                $previousStatus,
                $status,
                trim($data['reason']),
            ),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة المحفظة وتسجيل الإجراء.',
            'data' => $wallet->fresh(),
        ]);
    }

    private function overviewPayload(): array
    {
        $familyWallets = Wallet::query()
            ->whereIn('owner_type', self::FAMILY_OWNER_TYPES);
        $driverWallets = Wallet::query()
            ->whereIn('owner_type', self::DRIVER_OWNER_TYPES);
        $settlements = OrderSettlement::query()
            ->where('status', '!=', OrderSettlement::STATUS_REVERSED);
        $releasedSettlements = OrderSettlement::query()
            ->where('status', OrderSettlement::STATUS_RELEASED);
        $unreleasedSettlements = OrderSettlement::query()
            ->whereIn('status', [
                OrderSettlement::STATUS_PENDING,
                OrderSettlement::STATUS_HELD,
            ]);

        return [
            'total_wallets' => Wallet::query()->count(),
            'family_wallets' => (clone $familyWallets)->count(),
            'driver_wallets' => (clone $driverWallets)->count(),

            'family_available' => (float) (clone $familyWallets)
                ->sum('available_balance'),
            'family_pending' => (float) (clone $familyWallets)
                ->sum('pending_balance'),
            'family_total' => (float) (clone $familyWallets)
                ->sum('available_balance')
                + (float) (clone $familyWallets)->sum('pending_balance'),

            'driver_available' => (float) (clone $driverWallets)
                ->sum('available_balance'),
            'driver_pending' => (float) (clone $driverWallets)
                ->sum('pending_balance'),
            'driver_total' => (float) (clone $driverWallets)
                ->sum('available_balance')
                + (float) (clone $driverWallets)->sum('pending_balance'),

            'platform_commissions_total' => (float) (clone $settlements)
                ->sum('platform_total'),
            'platform_commissions_released' => (float) (clone $releasedSettlements)
                ->sum('platform_total'),
            'platform_commissions_pending' => (float) (clone $unreleasedSettlements)
                ->sum('platform_total'),
            'family_commissions_total' => (float) (clone $settlements)
                ->sum('family_commission'),
            'driver_commissions_total' => (float) (clone $settlements)
                ->sum('driver_commission'),
            'commissioned_orders_count' => (clone $settlements)->count(),

            'pending_settlements_count' => OrderSettlement::query()
                ->where('status', OrderSettlement::STATUS_PENDING)
                ->count(),
            'held_settlements_count' => OrderSettlement::query()
                ->where('status', OrderSettlement::STATUS_HELD)
                ->count(),
            'released_settlements_count' => (clone $releasedSettlements)->count(),

            'pending_payouts_count' => Payout::query()
                ->whereIn('status', ['pending', 'approved', 'processing'])
                ->count(),
            'pending_payouts_amount' => (float) Payout::query()
                ->whereIn('status', ['pending', 'approved', 'processing'])
                ->sum('amount'),
        ];
    }

    private function applyOwnerTypeFilter(
        Builder $query,
        ?string $ownerType,
    ): void {
        if ($ownerType === 'family') {
            $query->whereIn('owner_type', self::FAMILY_OWNER_TYPES);

            return;
        }

        if ($ownerType === 'driver') {
            $query->whereIn('owner_type', self::DRIVER_OWNER_TYPES);

            return;
        }

        if ($ownerType !== null) {
            $query->where('owner_type', $ownerType);
        }
    }

    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === null) {
            return;
        }

        $query->where('status', $status);
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $familyIds = ProductiveFamily::query()
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            })
            ->limit(500)
            ->pluck('id');

        $driverIds = Driver::query()
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            })
            ->limit(500)
            ->pluck('id');

        $walletId = preg_match('/(\d+)/', $search, $matches)
            ? (int) $matches[1]
            : null;

        $query->where(function (Builder $query) use (
            $familyIds,
            $driverIds,
            $walletId,
        ): void {
            if (
                $walletId === null &&
                $familyIds->isEmpty() &&
                $driverIds->isEmpty()
            ) {
                $query->whereRaw('1 = 0');

                return;
            }

            if ($walletId !== null) {
                $query->orWhereKey($walletId);
            }

            if ($familyIds->isNotEmpty()) {
                $query->orWhere(function (Builder $query) use ($familyIds): void {
                    $query
                        ->whereIn('owner_type', self::FAMILY_OWNER_TYPES)
                        ->whereIn('owner_id', $familyIds);
                });
            }

            if ($driverIds->isNotEmpty()) {
                $query->orWhere(function (Builder $query) use ($driverIds): void {
                    $query
                        ->whereIn('owner_type', self::DRIVER_OWNER_TYPES)
                        ->whereIn('owner_id', $driverIds);
                });
            }
        });
    }

    private function ownersFor(Collection $wallets): array
    {
        $familyIds = $wallets
            ->filter(fn (Wallet $wallet): bool =>
                in_array($wallet->owner_type, self::FAMILY_OWNER_TYPES, true))
            ->pluck('owner_id')
            ->filter()
            ->unique();

        $driverIds = $wallets
            ->filter(fn (Wallet $wallet): bool =>
                in_array($wallet->owner_type, self::DRIVER_OWNER_TYPES, true))
            ->pluck('owner_id')
            ->filter()
            ->unique();

        return [
            'families' => ProductiveFamily::query()
                ->with('city')
                ->whereIn('id', $familyIds)
                ->get()
                ->keyBy('id'),
            'drivers' => Driver::query()
                ->with('city')
                ->whereIn('id', $driverIds)
                ->get()
                ->keyBy('id'),
        ];
    }

    private function walletPayload(Wallet $wallet, array $owners): array
    {
        $ownerType = $this->ownerType($wallet->owner_type);
        $owner = match ($ownerType) {
            'family' => $owners['families']->get($wallet->owner_id),
            'driver' => $owners['drivers']->get($wallet->owner_id),
            default => null,
        };

        $name = match ($ownerType) {
            'family' => $owner?->owner_name,
            'driver' => $owner?->name,
            'platform' => 'محفظة زاد الرئيسية',
            default => $owner?->name,
        };

        return [
            'id' => $wallet->id,
            'wallet_number' => 'ZAD-WAL-'.str_pad(
                (string) $wallet->id,
                8,
                '0',
                STR_PAD_LEFT,
            ),
            'owner_type' => $ownerType,
            'owner_id' => $wallet->owner_id,
            'owner' => [
                'id' => $owner?->id ?? $wallet->owner_id,
                'name' => $name ?: 'غير معروف',
                'phone' => $owner?->phone,
                'email' => $owner?->email,
                'code' => $owner?->code,
            ],
            'city_id' => $owner?->city_id,
            'city' => $owner?->city === null
                ? null
                : [
                    'id' => $owner->city->id,
                    'name' => $owner->city->name ?? null,
                    'name_ar' => $owner->city->name_ar ?? null,
                    'name_en' => $owner->city->name_en ?? null,
                ],
            'available_balance' => (float) $wallet->available_balance,
            'pending_balance' => (float) $wallet->pending_balance,
            'total_balance' => round(
                (float) $wallet->available_balance
                + (float) $wallet->pending_balance,
                2,
            ),
            'total_earned' => (float) ($wallet->total_earned ?? 0),
            'total_withdrawn' => (float) ($wallet->total_withdrawn ?? 0),
            'currency' => $wallet->currency,
            'status' => $wallet->status ?: (
                $wallet->is_frozen ? 'frozen' : 'active'
            ),
            'is_frozen' => (bool) $wallet->is_frozen,
            'open_payouts_count' => (int) ($wallet->open_payouts_count ?? 0),
            'last_transaction_at' => $wallet->last_transaction_at,
            'created_at' => $wallet->created_at?->toIso8601String(),
            'updated_at' => $wallet->updated_at?->toIso8601String(),
        ];
    }

    private function ownerType(string $ownerType): string
    {
        if (in_array($ownerType, self::FAMILY_OWNER_TYPES, true)) {
            return 'family';
        }

        if (in_array($ownerType, self::DRIVER_OWNER_TYPES, true)) {
            return 'driver';
        }

        return Str::lower(class_basename($ownerType));
    }
}
