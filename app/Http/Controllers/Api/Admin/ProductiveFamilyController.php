<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\ProductiveFamily;
use App\Models\RolePortalRecord;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductiveFamilyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductiveFamily::query()->with('store');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();

            $query->where(function ($query) use ($search): void {
                $query->where('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('metadata->family_name', 'like', "%{$search}%")
                    ->orWhere('metadata->store_name', 'like', "%{$search}%")
                    ->orWhereIn('id', RolePortalRecord::query()
                        ->where('role', 'family')
                        ->where('module', 'contract-acceptance')
                        ->where('owner_type', ProductiveFamily::class)
                        ->where(function ($contractQuery) use ($search): void {
                            $contractQuery->where('reference', 'like', "%{$search}%")
                                ->orWhere('payload->contract_reference', 'like', "%{$search}%")
                                ->orWhere('payload->document_hash', 'like', "%{$search}%");
                        })
                        ->whereNotNull('owner_id')
                        ->select('owner_id'))
                    ->orWhereHas('store', function ($storeQuery) use ($search): void {
                        $storeQuery->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('subscription_plan') && $request->string('subscription_plan')->toString() !== 'all') {
            $query->where('metadata->subscription_plan', $request->string('subscription_plan')->toString());
        }

        if ($request->filled('city')) {
            $query->where('metadata->city', 'like', '%'.$request->string('city')->trim()->toString().'%');
        }

        if ($request->filled('rating_from')) {
            $query->where('metadata->average_rating', '>=', (float) $request->input('rating_from'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $families = $query
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        $currentContract = $this->currentFamilyContract();

        $families->getCollection()->transform(
            fn (ProductiveFamily $family): array => $this->transformFamily($family, $currentContract),
        );

        return response()->json($families);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = DB::transaction(function () use ($request): ProductiveFamily {
            $family = ProductiveFamily::query()->create([
                'code' => $this->generateCode(),
                'owner_name' => $request->string('owner_name')->trim()->toString(),
                'phone' => $request->string('phone')->trim()->toString(),
                'email' => $request->filled('email') ? $request->string('email')->trim()->toString() : null,
                'health_certificate_number' => $request->input('health_certificate_number'),
                'health_certificate_expires_at' => $request->input('health_certificate_expires_at'),
                'status' => $request->input('status', 'pending'),
                'city_id' => $request->input('city_id'),
                'metadata' => $this->metadataFromRequest($request),
            ]);

            $this->syncStore($family, $request);

            return $family->fresh(['store']);
        });

        return response()->json([
            'message' => 'تمت إضافة الأسرة وإنشاء متجرها المرتبط بنجاح.',
            'data' => $this->transformFamily($family),
        ], 201);
    }

    public function update(Request $request, ProductiveFamily $family): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true));

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = DB::transaction(function () use ($request, $family): ProductiveFamily {
            $metadata = array_merge(
                is_array($family->metadata) ? $family->metadata : [],
                $this->metadataFromRequest($request, true),
            );

            $family->update([
                'owner_name' => $request->input('owner_name', $family->owner_name),
                'phone' => $request->input('phone', $family->phone),
                'email' => $request->exists('email') ? $request->input('email') : $family->email,
                'health_certificate_number' => $request->input('health_certificate_number', $family->health_certificate_number),
                'health_certificate_expires_at' => $request->input('health_certificate_expires_at', $family->health_certificate_expires_at),
                'status' => $request->input('status', $family->status),
                'city_id' => $request->input('city_id', $family->city_id),
                'metadata' => $metadata,
            ]);

            $this->syncStore($family->fresh(), $request);

            return $family->fresh(['store']);
        });

        return response()->json([
            'message' => 'تم تحديث الأسرة والمتجر المرتبط بها بنجاح.',
            'data' => $this->transformFamily($family),
        ]);
    }

    public function changeStatus(Request $request, ProductiveFamily $family): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:pending,active,approved,suspended,rejected,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'حالة الأسرة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $family = DB::transaction(function () use ($request, $family): ProductiveFamily {
            $status = $request->string('status')->toString();

            $family->update([
                'status' => $status,
                'approved_at' => in_array($status, ['active', 'approved'], true) ? now() : null,
                'approved_by' => in_array($status, ['active', 'approved'], true)
                    ? $request->user()?->id
                    : null,
            ]);

            $this->syncStore($family->fresh(), $request);

            return $family->fresh(['store']);
        });

        return response()->json([
            'message' => 'تم تغيير حالة الأسرة ومزامنة متجرها بنجاح.',
            'data' => $this->transformFamily($family),
        ]);
    }

    public function destroy(ProductiveFamily $family): JsonResponse
    {
        DB::transaction(function () use ($family): void {
            $store = $family->store()->first();

            if ($store !== null) {
                $store->update([
                    'status' => 'archived',
                    'is_open' => false,
                ]);
                $store->delete();
            }

            AppProfile::query()
                ->with('user')
                ->where('productive_family_id', $family->id)
                ->get()
                ->each(function (AppProfile $profile): void {
                    // نجبر التطبيق على طلب تسجيل جديد بدل استعمال جلسة قديمة.
                    $profile->user?->tokens()->delete();
                });

            $family->delete();
        });

        return response()->json([
            'message' => 'تم حذف الأسرة ومتجرها من القوائم وإعادة ضبط رحلة التسجيل. السجلات التاريخية تبقى مؤرشفة.',
        ]);
    }

    public function stats(): JsonResponse
    {
        $families = ProductiveFamily::query();

        return response()->json([
            'total' => (clone $families)->count(),
            'active' => (clone $families)->whereIn('status', ['active', 'approved'])->count(),
            'approved' => (clone $families)->whereIn('status', ['active', 'approved'])->count(),
            'pending' => (clone $families)->where('status', 'pending')->count(),
            'suspended' => (clone $families)->whereIn('status', ['suspended', 'inactive'])->count(),
            'rejected' => (clone $families)->where('status', 'rejected')->count(),
            'products' => Store::query()->withCount('products')->get()->sum('products_count'),
            'orders' => Store::query()->withCount('orders')->get()->sum('orders_count'),
            'wallet_balance' => (float) ProductiveFamily::query()->get()->sum(
                fn (ProductiveFamily $family): float => (float) data_get($family->metadata, 'wallet_balance', 0),
            ),
            'average_rating' => round((float) Store::query()->avg('rating'), 2),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $families = ProductiveFamily::query()->with('store')->latest()->get();

        return response()->streamDownload(function () use ($families): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Code', 'Family Name', 'Store Name', 'Owner Name', 'Phone', 'Email', 'Status']);

            foreach ($families as $family) {
                $metadata = is_array($family->metadata) ? $family->metadata : [];
                fputcsv($handle, [
                    $family->id,
                    $family->code,
                    $metadata['family_name'] ?? '',
                    $family->store?->name_ar ?? ($metadata['store_name'] ?? ''),
                    $family->owner_name,
                    $family->phone,
                    $family->email,
                    $family->status,
                ]);
            }

            fclose($handle);
        }, 'productive-families.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ZAD_CONTRACT_ADMIN_V1
    public function contract(ProductiveFamily $family): JsonResponse
    {
        return response()->json([
            'data' => $this->contractState($family, $this->currentFamilyContract()),
        ]);
    }

    public function downloadContractPdf(ProductiveFamily $family): \Illuminate\Http\Response
    {
        $acceptance = $this->latestFamilyContractAcceptance($family);

        abort_unless($acceptance !== null, 404, 'لا توجد نسخة عقد موقعة لهذه الأسرة حتى الآن.');

        $payload = is_array($acceptance->payload) ? $acceptance->payload : [];
        $contract = RolePortalRecord::query()
            ->where('role', 'family')
            ->where('module', 'contract')
            ->where('version', $acceptance->version)
            ->orderByDesc('id')
            ->first();

        $title = trim((string) ($payload['contract_title'] ?? $contract?->title ?? 'عقد الأسرة المنتجة'));
        $content = (string) ($payload['contract_content'] ?? $contract?->content ?? '');
        $contractReference = (string) ($payload['contract_reference'] ?? $contract?->reference ?? '—');
        $acceptanceReference = (string) $acceptance->reference;
        $signedName = trim((string) ($payload['signed_name'] ?? $family->owner_name));
        $signedAt = $acceptance->effective_at?->format('Y-m-d H:i:s')
            ?? $acceptance->created_at?->format('Y-m-d H:i:s')
            ?? '—';
        $documentHash = (string) ($payload['document_hash'] ?? '—');
        $signature = (string) ($payload['signature_base64'] ?? '');
        $signatureHtml = preg_match('/^data:image\/(png|jpe?g|webp);base64,/i', $signature)
            ? '<img src="'.e($signature).'" style="max-width:220px;max-height:100px" alt="signature">'
            : '<strong>'.e($signedName !== '' ? $signedName : 'توقيع إلكتروني موثق').'</strong>';

        $tempDir = storage_path('app/mpdf-temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_top' => 14,
            'margin_right' => 14,
            'margin_bottom' => 14,
            'margin_left' => 14,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($title);

        $html = '<html lang="ar" dir="rtl"><head><style>
            body{font-family:dejavusans;direction:rtl;text-align:right;color:#111827;font-size:12px;line-height:1.8}
            .header{text-align:center;border-bottom:2px solid #0B2F61;padding-bottom:12px;margin-bottom:18px}
            .brand{font-size:22px;font-weight:bold;color:#0B2F61}.sub{color:#6b7280;font-size:10px}
            .meta{width:100%;border-collapse:collapse;margin:12px 0 18px}.meta td{border:1px solid #d1d5db;padding:7px;vertical-align:top}
            .label{font-weight:bold;color:#374151;width:25%}.content{white-space:pre-wrap;border:1px solid #d1d5db;padding:14px;border-radius:6px}
            .sign{margin-top:22px;border-top:1px solid #d1d5db;padding-top:14px}.verified{color:#047857;font-weight:bold}
            .hash{font-size:9px;word-break:break-all;color:#4b5563}
        </style></head><body>
        <div class="header"><div class="brand">ZAD Sync</div><div class="sub">نسخة العقد الإلكتروني الموقعة والمحـفوظة لدى الإدارة</div></div>
        <h2 style="text-align:center">'.e($title).'</h2>
        <table class="meta">
          <tr><td class="label">الأسرة المنتجة</td><td>'.e($family->owner_name).'</td><td class="label">رقم الأسرة</td><td>'.e((string) $family->id).'</td></tr>
          <tr><td class="label">مرجع العقد</td><td>'.e($contractReference).'</td><td class="label">إصدار العقد</td><td>'.e((string) $acceptance->version).'</td></tr>
          <tr><td class="label">مرجع التوقيع</td><td>'.e($acceptanceReference).'</td><td class="label">تاريخ التوقيع</td><td>'.e($signedAt).'</td></tr>
        </table>
        <div class="content">'.nl2br(e($content)).'</div>
        <div class="sign"><div class="verified">تم توقيع هذا الإصدار إلكترونيًا وتوثيقه في ZAD Sync.</div>
        <p>اسم الموقّع: <strong>'.e($signedName).'</strong></p>'.$signatureHtml.'
        <p class="hash">بصمة المستند: '.e($documentHash).'</p></div>
        </body></html>';

        $mpdf->WriteHTML($html);
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);
        $filename = 'zad-family-contract-'.$family->id.'-v'.$acceptance->version.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-ZAD-Contract-Version' => (string) $acceptance->version,
            'X-ZAD-Acceptance-Reference' => $acceptanceReference,
        ]);
    }

    private function currentFamilyContract(): ?RolePortalRecord
    {
        return RolePortalRecord::query()
            ->where('role', 'family')
            ->where('module', 'contract')
            ->where('status', 'published')
            ->where(function ($query): void {
                $query->whereNull('effective_at')->orWhere('effective_at', '<=', now());
            })
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    private function latestFamilyContractAcceptance(ProductiveFamily $family): ?RolePortalRecord
    {
        return RolePortalRecord::query()
            ->where('role', 'family')
            ->where('module', 'contract-acceptance')
            ->where('owner_type', ProductiveFamily::class)
            ->where('owner_id', $family->id)
            ->where('status', 'accepted')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    private function contractState(ProductiveFamily $family, ?RolePortalRecord $currentContract = null): array
    {
        $currentContract ??= $this->currentFamilyContract();
        $latestAcceptance = $this->latestFamilyContractAcceptance($family);
        $currentAcceptance = null;

        if ($currentContract !== null) {
            $currentAcceptance = RolePortalRecord::query()
                ->where('role', 'family')
                ->where('module', 'contract-acceptance')
                ->where('owner_type', ProductiveFamily::class)
                ->where('owner_id', $family->id)
                ->where('version', $currentContract->version)
                ->where('status', 'accepted')
                ->orderByDesc('id')
                ->first();
        }

        $status = match (true) {
            $currentContract === null => 'not_published',
            $currentAcceptance !== null => 'signed',
            $latestAcceptance !== null => 'resign_required',
            default => 'unsigned',
        };

        return [
            'contract_status' => $status,
            'contract_version' => $currentContract?->version,
            'contract_reference' => $currentContract?->reference,
            'contract_signed_version' => $latestAcceptance?->version,
            'contract_acceptance_reference' => $latestAcceptance?->reference,
            'contract_signed_at' => $latestAcceptance?->effective_at?->toIso8601String()
                ?? $latestAcceptance?->created_at?->toIso8601String(),
            'contract_has_signed_copy' => $latestAcceptance !== null,
        ];
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'family_name' => [$required, 'string', 'max:255'],
            'owner_name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:pending,active,approved,suspended,rejected,inactive'],
            'city_id' => ['nullable', 'integer'],
            'health_certificate_number' => ['nullable', 'string', 'max:255'],
            'health_certificate_expires_at' => ['nullable', 'date'],
        ];
    }

    private function syncStore(ProductiveFamily $family, Request $request): Store
    {
        $metadata = is_array($family->metadata) ? $family->metadata : [];
        $familyName = trim((string) ($metadata['family_name'] ?? $family->owner_name));
        $storeName = trim((string) ($request->input('store_name') ?: ($metadata['store_name'] ?? $familyName)));
        $storeName = $storeName !== '' ? $storeName : $familyName;

        $storeStatus = $this->storeStatusForFamily($family->status);
        $isOpen = in_array($storeStatus, ['active', 'approved'], true);

        $slug = $family->store?->slug ?: $this->uniqueStoreSlug($storeName, $family->id);

        return Store::query()->updateOrCreate(
            ['productive_family_id' => $family->id],
            [
                'city_id' => $family->city_id,
                'name_ar' => $storeName,
                'name_en' => $request->input('store_name_en', $family->store?->name_en),
                'slug' => $slug,
                'description_ar' => $request->input('store_description_ar', $family->store?->description_ar),
                'description_en' => $request->input('store_description_en', $family->store?->description_en),
                'status' => $storeStatus,
                'is_open' => $isOpen,
                'rating' => $family->store?->rating ?? 0,
                'rating_count' => $family->store?->rating_count ?? 0,
                'working_hours' => $family->store?->working_hours ?? [],
            ],
        );
    }

    private function storeStatusForFamily(string $familyStatus): string
    {
        return match ($familyStatus) {
            'active', 'approved' => 'active',
            'pending' => 'pending',
            'rejected' => 'rejected',
            default => 'suspended',
        };
    }

    private function uniqueStoreSlug(string $name, int $familyId): string
    {
        $base = Str::slug($name) ?: 'store-'.$familyId;
        $slug = $base;
        $counter = 1;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function metadataFromRequest(Request $request, bool $onlyProvided = false): array
    {
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

    private function transformFamily(ProductiveFamily $family, ?RolePortalRecord $currentContract = null): array
    {
        $family->loadMissing('store');
        $metadata = is_array($family->metadata) ? $family->metadata : [];
        $contractState = $this->contractState($family, $currentContract);

        return [
            'id' => $family->id,
            'code' => $family->code,
            'family_name' => $metadata['family_name'] ?? '',
            'owner_name' => $family->owner_name,
            'phone' => $family->phone,
            'email' => $family->email,
            'store_id' => $family->store?->id,
            'store_name' => $family->store?->name_ar ?? ($metadata['store_name'] ?? ''),
            'store_slug' => $family->store?->slug,
            'store_status' => $family->store?->status,
            'store_is_open' => (bool) ($family->store?->is_open ?? false),
            'city' => $metadata['city'] ?? '',
            'district' => $metadata['district'] ?? '',
            'subscription_plan' => $metadata['subscription_plan'] ?? 'free',
            'national_id' => $metadata['national_id'] ?? '',
            'notes' => $metadata['notes'] ?? '',
            'wallet_balance' => (float) ($metadata['wallet_balance'] ?? 0),
            'products_count' => $family->store?->products()->count() ?? (int) ($metadata['products_count'] ?? 0),
            'orders_count' => $family->store?->orders()->count() ?? (int) ($metadata['orders_count'] ?? 0),
            'average_rating' => (float) ($family->store?->rating ?? $metadata['average_rating'] ?? 0),
            'ai_score' => (float) ($metadata['ai_score'] ?? 0),
            ...$contractState,
            'status' => $family->status,
            'health_certificate_number' => $family->health_certificate_number,
            'health_certificate_expires_at' => $family->health_certificate_expires_at?->format('Y-m-d'),
            'created_at' => $family->created_at?->toISOString(),
            'updated_at' => $family->updated_at?->toISOString(),
        ];
    }

    private function generateCode(): string
    {
        do {
            $code = 'PF-'.Str::upper(Str::random(8));
        } while (ProductiveFamily::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
