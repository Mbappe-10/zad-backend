<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\RolePortalRecord;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FinancialService;
use App\Services\SecurePayoutProofService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        private readonly SecurePayoutProofService $payoutProofs,
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
            'iban_proof' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:10240',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'declaration_accepted' => ['required', 'accepted'],
            'signature_base64' => ['required', 'string', 'max:2000000'],
        ]);

        $policy = $this->payoutPolicy($context['role']);
        $declarationTemplate = $this->payoutDeclarationTemplate($context['role']);
        $contractState = $this->signedContract($context);
        abort_if($contractState === null, 428, 'يلزم توقيع العقد الإلكتروني المنشور والفعال قبل تقديم طلب السحب.');

        abort_if((float) $data['amount'] < (float) $policy['minimum_amount'], 422,
            'الحد الأدنى للسحب لهذا الحساب هو '.$policy['minimum_amount'].' ريال.');

        $ibanNormalized = $this->normalizeIban((string) $data['iban']);

        if (! $this->isValidSaudiIban($ibanNormalized)) {
            throw ValidationException::withMessages([
                'iban' => ['أدخل آيبان سعوديًا صحيحًا يبدأ بـ SA ويتكون من 24 خانة.'],
            ]);
        }

        if (Payout::query()
            ->where('iban_normalized', $ibanNormalized)
            ->where('wallet_id', '!=', $wallet->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'iban' => ['رقم الآيبان مرتبط بحساب آخر ولا يمكن استخدامه لهذا الحساب.'],
            ]);
        }

        $openPayout = Payout::query()->where('wallet_id', $wallet->id)
            ->whereIn('status', ['pending', 'approved', 'processing'])->exists();
        abort_if($openPayout, 422, 'يوجد طلب سحب مفتوح لهذا الحساب.');

        $lastPayout = Payout::query()->where('wallet_id', $wallet->id)
            ->where('status', '!=', 'rejected')->latest()->first();
        if ($lastPayout && $lastPayout->created_at->addDays((int) $policy['cycle_days'])->isFuture()) {
            abort(422, 'يتاح طلب السحب القادم بتاريخ '.$lastPayout->created_at
                ->addDays((int) $policy['cycle_days'])->format('Y-m-d H:i'));
        }

        abort_if(
            (float) $data['amount'] > (float) $wallet->available_balance,
            422,
            'المبلغ المطلوب أكبر من الرصيد المتاح.',
        );

        $signedAt = now();
        $declarationReference = 'ZADSYNC-WD-'.Str::upper($context['role']).'-'.$signedAt->format('YmdHis').'-'.Str::upper(Str::random(5));
        $fee = min((float) $policy['transfer_fee'], (float) $data['amount']);
        $declarationSnapshot = [
            'title' => $declarationTemplate?->title ?? 'إقرار طلب تحويل مستحقات مالية',
            'text' => $declarationTemplate?->content ?? $policy['declaration_text'],
            'template_reference' => $declarationTemplate?->reference,
            'template_version' => $declarationTemplate?->version,
            'logo_url' => data_get($declarationTemplate?->payload, 'logo_url'),
            'seal_url' => data_get($declarationTemplate?->payload, 'seal_url'),
            'amount' => (float) $data['amount'],
            'fee' => $fee,
            'net_amount' => round((float) $data['amount'] - $fee, 2),
            'bank_name' => $data['bank_name'],
            'account_name' => $data['account_name'],
            'iban_masked' => $this->maskIban($ibanNormalized),
            'contract_status' => 'signed_active_verified',
            'contract_reference' => $contractState['contract']->reference,
            'contract_version' => $contractState['contract']->version,
            'contract_acceptance_reference' => $contractState['acceptance']->reference,
            'signed_at' => $signedAt->toIso8601String(),
        ];
        $proof = $this->payoutProofs->upload($request->file('iban_proof'));
        $declarationSnapshot['iban_proof_sha256'] = $proof['sha256'];

        $declarationHash = hash('sha256', json_encode([
            $declarationReference, $declarationSnapshot, $policy,
            $data['signature_base64'], $request->user()->id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $acceptancePayload = $contractState['acceptance']->payload ?? [];
        try {
            $payout = $this->financialService->requestPayout(
                $wallet,
                [
                ...Arr::only($data, ['amount', 'bank_name', 'account_name', 'notes']),
                'iban' => $ibanNormalized,
                'iban_normalized' => $ibanNormalized,
                'iban_proof_required' => true,
                'iban_proof_public_id' => $proof['public_id'],
                'iban_proof_asset_id' => $proof['asset_id'],
                'iban_proof_resource_type' => $proof['resource_type'],
                'iban_proof_delivery_type' => $proof['delivery_type'],
                'iban_proof_format' => $proof['format'],
                'iban_proof_original_name' => $proof['original_name'],
                'iban_proof_mime_type' => $proof['mime_type'],
                'iban_proof_size' => $proof['size'],
                'iban_proof_sha256' => $proof['sha256'],
                'iban_proof_uploaded_at' => now(),
                'fee' => $fee,
                'account_role' => $context['role'],
                'contract_id' => $contractState['contract']->id,
                'contract_acceptance_id' => $contractState['acceptance']->id,
                'contract_reference' => $contractState['contract']->reference,
                'contract_version' => $contractState['contract']->version,
                'contract_acceptance_reference' => $contractState['acceptance']->reference,
                'contract_document_hash' => $acceptancePayload['document_hash'] ?? null,
                'contract_signed_at' => $contractState['acceptance']->effective_at,
                'declaration_reference' => $declarationReference,
                'declaration_version' => $declarationTemplate?->reference ?? $policy['declaration_version'],
                'declaration_signature' => $data['signature_base64'],
                'declaration_hash' => $declarationHash,
                'declaration_signed_at' => $signedAt,
                'policy_snapshot' => $policy,
                'declaration_snapshot' => $declarationSnapshot,
                ],
                $request->user()->id,
            );
        } catch (Throwable $exception) {
            try {
                $this->payoutProofs->delete(
                    $proof['public_id'],
                    $proof['resource_type'],
                    $proof['delivery_type'],
                );
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'تم توقيع إقرار السحب وإرسال الطلب للمراجعة.',
            'data' => $this->payoutPayload($payout),
        ], 201);
    }

    private function acceptContract(Request $request, array $context): JsonResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:role_portal_records,id'],
            'accepted' => ['required', 'accepted'],
            'signature_base64' => ['required', 'string', 'max:2000000'],
        ]);

        $contract = RolePortalRecord::query()
            ->whereKey($data['contract_id'])
            ->where('module', 'contract')
            ->where('role', $context['role'])
            ->where('status', 'published')
            ->firstOrFail();

        $acceptedAt = now();
        $hashSource = implode('|', [
            $contract->reference,
            (string) $contract->version,
            $contract->title,
            (string) $contract->content,
            $data['signature_base64'],
            $context['owner_type'],
            (string) $context['owner_id'],
            $acceptedAt->toIso8601String(),
        ]);
        $documentHash = hash('sha256', $hashSource);

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
                // تحفظ نسخة العقد نفسها، وليس رابطًا إلى نص قابل للتغيير.
                'content' => $contract->content,
                'payload' => [
                    'contract_id' => $contract->id,
                    'contract_reference' => $contract->reference,
                    'contract_title' => $contract->title,
                    'contract_content' => $contract->content,
                    'contract_version' => $contract->version,
                    'signature_base64' => $data['signature_base64'],
                    'document_hash' => $documentHash,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500),
                    'accepted_at' => $acceptedAt->toIso8601String(),
                    'brand' => [
                        'logo_asset' => 'assets/contracts/zad_logo.png',
                        'seal_asset' => 'assets/contracts/zad_seal.png',
                        'seal_label' => 'ZADSYNC PLATFORM',
                    ],
                ],
                'effective_at' => $acceptedAt,
            ],
        );

        return response()->json([
            'message' => 'تم توثيق موافقتك على العقد.',
            'data' => $this->recordPayload($acceptance),
            'document_hash' => $documentHash,
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
            'payout_policy' => $this->payoutPolicy($context['role']),
            'payout_eligibility' => $this->payoutEligibility($context, $wallet),
            'transactions' => WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->latest()
                ->limit(50)
                ->get(),
            'payouts' => Payout::query()
                ->where('wallet_id', $wallet->id)
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (Payout $payout): array => $this->payoutPayload($payout))
                ->values(),
        ];
    }

    private function payoutPayload(Payout $payout): array
    {
        $payload = $payout->makeHidden([
            'iban_proof_public_id',
            'iban_proof_asset_id',
            'iban_proof_resource_type',
            'iban_proof_delivery_type',
            'iban_proof_sha256',
            'iban_proof_deleted_by',
            'iban_proof_deletion_reason',
        ])->toArray();

        $payload['iban_proof'] = [
            'required' => (bool) $payout->iban_proof_required,
            'available' => $payout->iban_proof_uploaded_at !== null
                && $payout->iban_proof_deleted_at === null,
            'file_name' => $payout->iban_proof_original_name,
            'mime_type' => $payout->iban_proof_mime_type,
            'size' => $payout->iban_proof_size,
            'uploaded_at' => $payout->iban_proof_uploaded_at?->toIso8601String(),
            'deleted_at' => $payout->iban_proof_deleted_at?->toIso8601String(),
        ];

        return $payload;
    }

    private function payoutPolicy(string $role): array
    {
        $settings = PlatformControl::query()->where('section', 'finance')->value('value') ?? [];
        $family = $role === 'family';
        $template = $this->payoutDeclarationTemplate($role);

        return [
            'version' => (string) ($settings['payoutPolicyVersion'] ?? 'PAYOUT-POLICY-V1'),
            'role' => $role,
            'minimum_amount' => (float) ($settings[$family ? 'familyMinimumWithdrawalAmount' : 'courierMinimumWithdrawalAmount'] ?? ($family ? 80 : 100)),
            'cycle_days' => (int) ($settings[$family ? 'familyPayoutCycleDays' : 'courierPayoutCycleDays'] ?? ($family ? 5 : 21)),
            'processing_days' => (int) ($settings[$family ? 'familyPayoutProcessingDays' : 'courierPayoutProcessingDays'] ?? ($family ? 1 : 3)),
            'hold_hours' => (int) ($settings[$family ? 'familyPayoutHoldHours' : 'courierPayoutHoldHours'] ?? 24),
            'transfer_fee' => (float) ($settings['payoutTransferFee'] ?? 0),
            'declaration_version' => (string) ($template?->reference ?? ($settings[$family ? 'familyPayoutDeclarationVersion' : 'courierPayoutDeclarationVersion'] ?? ($family ? 'WITHDRAWAL-FAMILY-V1' : 'WITHDRAWAL-DRIVER-V1'))),
            'declaration_title' => $template?->title ?? 'إقرار طلب تحويل مستحقات مالية',
            'declaration_text' => (string) ($template?->content ?? ($settings[$family ? 'familyPayoutDeclarationText' : 'courierPayoutDeclarationText'] ?? 'أقر بصحة بيانات طلب السحب وأوافق على السياسة المطبقة.')),
            'declaration_logo_url' => data_get($template?->payload, 'logo_url'),
            'declaration_seal_url' => data_get($template?->payload, 'seal_url'),
            'effective_at' => now()->toIso8601String(),
        ];
    }

    private function payoutDeclarationTemplate(string $role): ?RolePortalRecord
    {
        return RolePortalRecord::query()
            ->where('role', $role)
            ->where('module', 'payout-declaration')
            ->where('status', 'published')
            ->where(fn (Builder $query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    private function signedContract(array $context): ?array
    {
        $contract = RolePortalRecord::query()
            ->where('role', $context['role'])->where('module', 'contract')
            ->where('status', 'published')
            ->where(fn (Builder $query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->orderByDesc('version')->orderByDesc('id')->first();
        if (! $contract) return null;

        $acceptance = RolePortalRecord::query()
            ->where('role', $context['role'])->where('module', 'contract-acceptance')
            ->where('owner_type', $context['owner_type'])->where('owner_id', $context['owner_id'])
            ->where('version', $contract->version)->where('status', 'accepted')->first();

        return $acceptance ? compact('contract', 'acceptance') : null;
    }

    private function payoutEligibility(array $context, Wallet $wallet): array
    {
        $policy = $this->payoutPolicy($context['role']);
        $signedContract = $this->signedContract($context);
        if ($signedContract === null) return ['eligible' => false, 'code' => 'contract_required', 'message' => 'يلزم توقيع العقد الحالي أولًا.'];
        $contractSummary = [
            'id' => $signedContract['contract']->id,
            'reference' => $signedContract['contract']->reference,
            'version' => $signedContract['contract']->version,
            'status' => 'signed_active_verified',
            'acceptance_reference' => $signedContract['acceptance']->reference,
            'signed_at' => $signedContract['acceptance']->effective_at,
        ];
        if ($wallet->is_frozen) return ['eligible' => false, 'code' => 'wallet_frozen', 'message' => 'المحفظة مجمدة مؤقتًا.'];
        if ((float) $wallet->available_balance < (float) $policy['minimum_amount']) return ['eligible' => false, 'code' => 'minimum_not_met', 'message' => 'لم يصل الرصيد إلى الحد الأدنى للسحب.'];
        if (Payout::query()->where('wallet_id', $wallet->id)->whereIn('status', ['pending', 'approved', 'processing'])->exists()) return ['eligible' => false, 'code' => 'open_payout', 'message' => 'يوجد طلب سحب مفتوح.'];
        $last = Payout::query()->where('wallet_id', $wallet->id)->where('status', '!=', 'rejected')->latest()->first();
        $next = $last?->created_at?->copy()->addDays((int) $policy['cycle_days']);
        if ($next?->isFuture()) return ['eligible' => false, 'code' => 'cycle_pending', 'message' => 'لم تكتمل دورة السحب.', 'next_eligible_at' => $next->toIso8601String()];
        return ['eligible' => true, 'code' => 'ready', 'message' => 'الحساب مؤهل لطلب السحب.', 'contract' => $contractSummary];
    }

    private function maskIban(string $iban): string
    {
        $clean = preg_replace('/\s+/', '', $iban) ?? $iban;
        return strlen($clean) <= 8 ? str_repeat('*', max(strlen($clean) - 4, 0)).substr($clean, -4) : substr($clean, 0, 4).str_repeat('*', strlen($clean) - 8).substr($clean, -4);
    }

    private function normalizeIban(string $iban): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');
    }

    private function isValidSaudiIban(string $iban): bool
    {
        if (! preg_match('/^SA\d{22}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        $remainder = 0;

        foreach (str_split($numeric) as $digit) {
            $remainder = (($remainder * 10) + (int) $digit) % 97;
        }

        return $remainder === 1;
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
            ->orderByDesc('version')
            ->orderByDesc('id')
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
