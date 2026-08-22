<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Payout;
use App\Models\ProductiveFamily;
use App\Models\RolePortalRecord;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FinancialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RolePortalController extends Controller
{
    private const MODULES = [
        'sales',
        'wallet',
        'contract',
        'documents',
        'change-requests',
        'support',
        'offers',
        'reviews',
        'settings',
    ];

    public function __construct(
        private readonly FinancialService $financialService,
    ) {
    }

    public function show(
        Request $request,
        string $role,
        string $module,
    ): JsonResponse {
        $context = $this->context($request, $role);
        $this->assertModule($module);

        return match ($module) {
            'sales' => response()->json([
                'data' => $this->salesData($context),
            ]),
            'wallet' => response()->json([
                'data' => $this->walletData($context),
            ]),
            'contract' => response()->json([
                'data' => $this->contractData($context),
            ]),
            'reviews' => response()->json([
                'data' => $this->reviewsData($context),
            ]),
            default => response()->json([
                'data' => $this->records($context, $module),
            ]),
        };
    }

    public function store(
        Request $request,
        string $role,
        string $module,
    ): JsonResponse {
        $context = $this->context($request, $role);
        $this->assertModule($module);

        if ($module === 'wallet') {
            return $this->requestPayout($request, $context);
        }

        if ($module === 'contract') {
            return $this->acceptContract($request, $context);
        }

        abort_if(in_array($module, ['sales', 'reviews'], true), 405);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'category' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'notifications_enabled' => ['nullable', 'boolean'],
            'privacy_enabled' => ['nullable', 'boolean'],
        ]);

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store("role-portal/{$role}/{$module}", 'public')
            : null;

        $status = $module === 'settings' ? 'active' : 'pending';
        $record = RolePortalRecord::query()->create([
            'reference' => $this->reference($module),
            'role' => $role,
            'module' => $module,
            'owner_type' => $context['owner_type'],
            'owner_id' => $context['owner_id'],
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => $status,
            'content' => $data['description'] ?? null,
            'payload' => [
                'category' => $data['category'] ?? null,
                'phone' => $data['phone'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'notifications_enabled' => $data['notifications_enabled'] ?? null,
                'privacy_enabled' => $data['privacy_enabled'] ?? null,
            ],
            'attachment_path' => $attachmentPath,
        ]);

        return response()->json([
            'message' => $module === 'support'
                ? 'تم فتح تذكرة الدعم بنجاح.'
                : 'تم إرسال الطلب للمراجعة.',
            'data' => $this->recordPayload($record),
        ], 201);
    }

    private function requestPayout(Request $request, array $context): JsonResponse
    {
        $wallet = $this->wallet($context);
        abort_unless($wallet !== null, 422, 'لا توجد محفظة مرتبطة بهذا الحساب.');
        abort_if($wallet->is_frozen, 422, 'المحفظة مجمدة مؤقتًا.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'bank_name' => ['required', 'string', 'max:150'],
            'iban' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if(
            (float) $data['amount'] > (float) $wallet->available_balance,
            422,
            'المبلغ المطلوب أكبر من الرصيد المتاح.',
        );

        $payout = $this->financialService->requestPayout(
            $wallet,
            [...$data, 'fee' => 0],
            $request->user()->id,
        );

        return response()->json([
            'message' => 'تم إرسال طلب السحب للمراجعة.',
            'data' => $payout,
        ], 201);
    }

    private function acceptContract(Request $request, array $context): JsonResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:role_portal_records,id'],
            'accepted' => ['required', 'accepted'],
        ]);

        $contract = RolePortalRecord::query()
            ->whereKey($data['contract_id'])
            ->where('module', 'contract')
            ->where('role', $context['role'])
            ->where('status', 'published')
            ->firstOrFail();

        $acceptance = RolePortalRecord::query()->updateOrCreate(
            [
                'role' => $context['role'],
                'module' => 'contract-acceptance',
                'owner_type' => $context['owner_type'],
                'owner_id' => $context['owner_id'],
                'version' => $contract->version,
            ],
            [
                'reference' => $this->reference('acceptance'),
                'user_id' => $request->user()->id,
                'title' => $contract->title,
                'status' => 'accepted',
                'content' => 'تمت الموافقة الإلكترونية على العقد.',
                'payload' => [
                    'contract_id' => $contract->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500),
                    'accepted_at' => now()->toIso8601String(),
                ],
                'effective_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'تم توثيق موافقتك على العقد.',
            'data' => $this->recordPayload($acceptance),
        ]);
    }

    private function salesData(array $context): array
    {
        $orders = $this->orders($context);
        $completed = (clone $orders)
            ->whereIn('status', Order::completedStatuses())
            ->where('payment_status', Order::PAYMENT_PAID);

        return [
            'today_sales' => (float) (clone $completed)
                ->whereDate('delivered_at', today())
                ->sum('total'),
            'week_sales' => (float) (clone $completed)
                ->where('delivered_at', '>=', now()->subDays(6)->startOfDay())
                ->sum('total'),
            'month_sales' => (float) (clone $completed)
                ->whereMonth('delivered_at', now()->month)
                ->whereYear('delivered_at', now()->year)
                ->sum('total'),
            'completed_orders' => (clone $completed)->count(),
            'orders' => (clone $completed)
                ->latest('delivered_at')
                ->limit(30)
                ->get(['id', 'number', 'total', 'status', 'delivered_at']),
        ];
    }

    private function walletData(array $context): array
    {
        $wallet = $this->wallet($context);

        if (! $wallet) {
            return [
                'wallet' => null,
                'transactions' => [],
                'payouts' => [],
            ];
        }

        return [
            'wallet' => $wallet,
            'transactions' => WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->latest()
                ->limit(50)
                ->get(),
            'payouts' => Payout::query()
                ->where('wallet_id', $wallet->id)
                ->latest()
                ->limit(30)
                ->get(),
        ];
    }

    private function contractData(array $context): array
    {
        $contract = RolePortalRecord::query()
            ->where('role', $context['role'])
            ->where('module', 'contract')
            ->where('status', 'published')
            ->where(function (Builder $query): void {
                $query->whereNull('effective_at')
                    ->orWhere('effective_at', '<=', now());
            })
            ->latest('version')
            ->first();

        if (! $contract) {
            return ['contract' => null, 'acceptance' => null];
        }

        $acceptance = RolePortalRecord::query()
            ->where('role', $context['role'])
            ->where('module', 'contract-acceptance')
            ->where('owner_type', $context['owner_type'])
            ->where('owner_id', $context['owner_id'])
            ->where('version', $contract->version)
            ->where('status', 'accepted')
            ->first();

        return [
            'contract' => $this->recordPayload($contract),
            'acceptance' => $acceptance ? $this->recordPayload($acceptance) : null,
        ];
    }

    private function reviewsData(array $context): array
    {
        $rating = $context['role'] === 'family'
            ? (float) $context['entity']->store?->rating
            : (float) $context['entity']->rating;

        return [
            'average_rating' => $rating,
            'reviews_count' => 0,
            'items' => [],
        ];
    }

    private function records(array $context, string $module): array
    {
        return RolePortalRecord::query()
            ->where('role', $context['role'])
            ->where('module', $module)
            ->where('owner_type', $context['owner_type'])
            ->where('owner_id', $context['owner_id'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (RolePortalRecord $record): array => $this->recordPayload($record))
            ->values()
            ->all();
    }

    private function context(Request $request, string $role): array
    {
        abort_unless(in_array($role, ['family', 'driver'], true), 404);

        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($role === 'family') {
            abort_unless($profile->productive_family_id !== null, 403);
            $entity = ProductiveFamily::query()
                ->with('store')
                ->findOrFail((int) $profile->productive_family_id);
            abort_unless($entity->store !== null, 422, 'لا يوجد متجر مرتبط بالأسرة.');

            return [
                'role' => $role,
                'owner_type' => ProductiveFamily::class,
                'owner_id' => $entity->id,
                'entity' => $entity,
                'store_id' => $entity->store->id,
            ];
        }

        abort_unless($profile->driver_id !== null, 403);
        $entity = Driver::query()->findOrFail((int) $profile->driver_id);

        return [
            'role' => $role,
            'owner_type' => Driver::class,
            'owner_id' => $entity->id,
            'entity' => $entity,
            'store_id' => null,
        ];
    }

    private function orders(array $context): Builder
    {
        return Order::query()->when(
            $context['role'] === 'family',
            fn (Builder $query): Builder => $query->where('store_id', $context['store_id']),
            fn (Builder $query): Builder => $query->where('driver_id', $context['owner_id']),
        );
    }

    private function wallet(array $context): ?Wallet
    {
        return Wallet::query()
            ->where('owner_type', $context['owner_type'])
            ->where('owner_id', $context['owner_id'])
            ->where('currency', 'SAR')
            ->first();
    }

    private function recordPayload(RolePortalRecord $record): array
    {
        return [
            'id' => $record->id,
            'reference' => $record->reference,
            'role' => $record->role,
            'module' => $record->module,
            'title' => $record->title,
            'status' => $record->status,
            'version' => $record->version,
            'content' => $record->content,
            'payload' => $record->payload ?? [],
            'attachment_url' => $record->attachment_path
                ? url(Storage::url($record->attachment_path))
                : null,
            'effective_at' => $record->effective_at,
            'expires_at' => $record->expires_at,
            'reviewed_at' => $record->reviewed_at,
            'created_at' => $record->created_at,
        ];
    }

    private function assertModule(string $module): void
    {
        abort_unless(in_array($module, self::MODULES, true), 404);
    }

    private function reference(string $module): string
    {
        return strtoupper(Str::slug($module)).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }
}
