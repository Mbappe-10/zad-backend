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

        abort_unless($contract !== null, 404, 'تعذر العثور على إصدار العقد المرتبط بهذا التوقيع.');

        $contractPayload = is_array($contract->payload) ? $contract->payload : [];
        $title = trim((string) ($payload['contract_title'] ?? $contract->title ?? 'عقد الأسرة المنتجة'));
        $content = (string) ($payload['contract_content'] ?? $contract->content ?? '');
        $contractReference = (string) ($payload['contract_reference'] ?? $contract->reference ?? '-');
        $acceptanceReference = (string) $acceptance->reference;
        $signedAt = (string) ($payload['accepted_at']
            ?? $acceptance->effective_at?->toIso8601String()
            ?? $acceptance->created_at?->toIso8601String()
            ?? '-');
        $documentHash = (string) ($payload['document_hash'] ?? '-');

        $defaultLogo = resource_path('contracts/zad_logo.png');
        $defaultSeal = resource_path('contracts/zad_seal.png');
        $fontFile = resource_path('contracts/ZADArabic.ttf');

        $resolveBrandImage = static function (?string $url, string $fallback): string {
            $value = trim((string) $url);

            if ($value !== '' && str_starts_with($value, 'data:image/')) {
                return $value;
            }

            if ($value !== '') {
                $path = parse_url($value, PHP_URL_PATH);
                if (is_string($path) && str_contains($path, '/storage/')) {
                    $relative = ltrim((string) substr($path, strpos($path, '/storage/') + 9), '/');
                    $local = storage_path('app/public/'.$relative);
                    if (is_file($local)) {
                        return $local;
                    }
                }

                if (is_file($value)) {
                    return $value;
                }
            }

            return is_file($fallback) ? $fallback : '';
        };

        $logoSource = $resolveBrandImage(
            is_string($contractPayload['logo_url'] ?? null) ? $contractPayload['logo_url'] : null,
            $defaultLogo,
        );
        $sealSource = $resolveBrandImage(
            is_string($contractPayload['seal_url'] ?? null) ? $contractPayload['seal_url'] : null,
            $defaultSeal,
        );

        $signatureValue = trim((string) ($payload['signature_base64'] ?? ''));
        $signatureSource = '';
        if ($signatureValue !== '') {
            if (preg_match('/^data:image\\/(?:png|jpe?g|webp);base64,/i', $signatureValue)) {
                $signatureSource = $signatureValue;
            } else {
                $decodedSignature = base64_decode($signatureValue, true);
                if ($decodedSignature !== false && $decodedSignature !== '') {
                    $imageInfo = function_exists('getimagesizefromstring')
                        ? @getimagesizefromstring($decodedSignature)
                        : false;
                    $mime = is_array($imageInfo) && is_string($imageInfo['mime'] ?? null)
                        ? $imageInfo['mime']
                        : 'image/png';
                    $signatureSource = 'data:'.$mime.';base64,'.base64_encode($decodedSignature);
                }
            }
        }

        $paragraphHtml = '';
        $paragraphs = preg_split('/\\R+/u', str_replace(["\\r\\n", "\\r"], "\\n", $content)) ?: [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $isHeading = preg_match(
                '/^(\\d+[.\\-)؛:]|[٠-٩]+[.\\-)؛:]|تمهيد|الإقرار النهائي|بسم الله|preamble|introduction|final declaration|terms|article|section)/ui',
                $paragraph,
            ) === 1 || mb_strlen($paragraph) < 55;

            $paragraphHtml .= '<div class="contract-paragraph'.($isHeading ? ' contract-heading' : '').'">'
                .e($paragraph)
                .'</div>';
        }

        $tempDir = storage_path('app/mpdf-temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $config = [
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_top' => 31,
            'margin_right' => 15,
            'margin_bottom' => 18,
            'margin_left' => 15,
            'margin_header' => 7,
            'margin_footer' => 7,
        ];

        if (is_file($fontFile)) {
            $fontConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
            $fontDirs = $fontConfig['fontDir'];
            $fontVariables = (new \Mpdf\Config\FontVariables())->getDefaults();
            $fontData = $fontVariables['fontdata'];
            $config['fontDir'] = array_merge($fontDirs, [dirname($fontFile)]);
            $config['fontdata'] = $fontData + [
                'zadarabic' => [
                    'R' => basename($fontFile),
                    'B' => basename($fontFile),
                ],
            ];
            $config['default_font'] = 'zadarabic';
        }

        $mpdf = new Mpdf($config);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('منصة زاد سينك');
        $mpdf->SetCreator('ZADSYNC Contract System');

        $logoHtml = $logoSource !== ''
            ? '<img src="'.e($logoSource).'" style="width:70px;height:55px" alt="ZAD">'
            : '';

        $header = '<table width="100%" style="border-collapse:collapse;direction:ltr">'
            .'<tr>'
            .'<td width="70" style="width:70px"></td>'
            .'<td style="text-align:center;direction:rtl">'
            .'<div style="font-size:15pt;font-weight:bold;color:#111827">منصة زاد سينك</div>'
            .'<div style="font-size:9pt;color:#6b7280;margin-top:3px">وثيقة إلكترونية موثقة</div>'
            .'</td>'
            .'<td width="70" style="width:70px;text-align:right">'.$logoHtml.'</td>'
            .'</tr></table>'
            .'<div style="border-bottom:1.5px solid #0b2f61;margin-top:6px"></div>';

        $footer = '<table width="100%" style="border-collapse:collapse;direction:ltr;font-size:8pt">'
            .'<tr>'
            .'<td style="text-align:left">صفحة {PAGENO} من {nbpg}</td>'
            .'<td style="text-align:right;direction:rtl">المرجع: '.e($acceptanceReference).'</td>'
            .'</tr></table>';

        $mpdf->SetHTMLHeader($header);
        $mpdf->SetHTMLFooter($footer);

        $signatureHtml = $signatureSource !== ''
            ? '<img src="'.e($signatureSource).'" style="width:150px;max-height:70px" alt="signature">'
            : '<div style="font-size:9pt">التوقيع محفوظ إلكترونيًا</div>';

        $sealHtml = $sealSource !== ''
            ? '<img src="'.e($sealSource).'" style="width:120px;height:120px" alt="ZAD seal">'
            : '<div style="font-size:9pt">اعتماد منصة زاد سينك</div>';

        $html = '<html lang="ar" dir="rtl"><head><style>
            body{direction:rtl;text-align:right;color:#111827;font-size:11.2pt;line-height:1.75}
            .contract-title{text-align:center;color:#0b2f61;font-size:18pt;font-weight:bold;margin:0 0 8px}
            .contract-version{text-align:center;font-size:10pt;margin:0 0 18px}
            .contract-paragraph{margin:0 0 9px;text-align:right;line-height:1.8}
            .contract-heading{color:#0b2f61;font-size:12.2pt;font-weight:bold}
            .verification{border:1px solid #9ca3af;border-radius:6px;padding:12px;margin-top:20px}
            .verification-title{font-size:13pt;font-weight:bold;margin-bottom:8px}
            .verify-table{width:100%;border-collapse:collapse}
            .verify-table td{padding:4px 0;vertical-align:top}
            .verify-label{font-weight:bold;font-size:9.5pt;width:28%;text-align:right}
            .verify-value{font-size:8.5pt;text-align:left;direction:ltr}
            .signatures{width:100%;border-collapse:collapse;margin-top:18px;direction:ltr}
            .signatures td{width:50%;vertical-align:bottom;text-align:center}
            .sign-label{font-size:10pt;font-weight:bold;direction:rtl;margin-bottom:6px}
        </style></head><body>'
        .'<div class="contract-title">'.e($title).'</div>'
        .'<div class="contract-version">الإصدار '.e((string) ($payload['contract_version'] ?? $contract->version ?? '-')).'</div>'
        .$paragraphHtml
        .'<div class="verification">'
        .'<div class="verification-title">بيانات التوثيق</div>'
        .'<table class="verify-table">'
        .'<tr><td class="verify-label">رقم العقد</td><td class="verify-value">'.e($contractReference).'</td></tr>'
        .'<tr><td class="verify-label">رقم الموافقة</td><td class="verify-value">'.e($acceptanceReference).'</td></tr>'
        .'<tr><td class="verify-label">تاريخ ووقت التوقيع</td><td class="verify-value">'.e($signedAt).'</td></tr>'
        .'<tr><td class="verify-label">بصمة المستند SHA-256</td><td class="verify-value" style="word-break:break-all">'.e($documentHash).'</td></tr>'
        .'</table></div>'
        .'<table class="signatures"><tr>'
        .'<td><div class="sign-label">اعتماد منصة زاد سينك</div>'.$sealHtml.'</td>'
        .'<td><div class="sign-label">توقيع صاحب الحساب</div>'.$signatureHtml.'</td>'
        .'</tr></table>'
        .'</body></html>';

        $mpdf->WriteHTML($html);
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);
        $safeReference = preg_replace('/[^A-Za-z0-9_-]+/', '-', $acceptanceReference) ?: 'contract';
        $filename = 'ZADSYNC-'.$safeReference.'.pdf';

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
