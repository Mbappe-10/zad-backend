<?php

namespace App\Services\App;

use App\Models\SaleReceipt;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Models\ZatcaDocument;
use App\Models\ZatcaEgsUnit;
use Carbon\CarbonInterface;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\ZatcaApiException;
use Saleh7\Zatca\GeneratorInvoice;
use Saleh7\Zatca\Helpers\Certificate;
use Saleh7\Zatca\InvoiceSigner;
use Saleh7\Zatca\Mappers\InvoiceMapper;
use Saleh7\Zatca\ZatcaAPI;

/**
 * ZATCA e-invoicing phase 2 (Fatoora integration).
 *
 * The server is the store's EGS (e-invoice generation solution): it keeps the keys and
 * certificates, numbers every invoice (ICV), chains it to the previous one (PIH), signs
 * it (XAdES, secp256k1), puts the phase-2 QR on it, then reports simplified invoices
 * (within 24 h) or clears standard (B2B) invoices with ZATCA.
 */
class ZatcaService
{
    /** PIH of the very first invoice: base64 of SHA-256("0") in hex, as ZATCA specifies. */
    public const INITIAL_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public const VAT_RATE = 15;

    public const ID_TYPES = [
        'CRN' => 'سجل تجاري',
        '700' => 'الرقم الموحد (700)',
        'MOM' => 'ترخيص وزارة الموارد البشرية',
        'MLS' => 'ترخيص وزارة الشؤون البلدية',
        'SAG' => 'ترخيص وزارة الاستثمار',
        'OTH' => 'رقم آخر (مثل وثيقة العمل الحر)',
    ];

    public const PAYMENT_CODES = ['cash' => '10', 'network' => '48', 'apple_pay' => '48', 'transfer' => '42', 'other' => '1'];

    public function ready(): bool
    {
        return Schema::hasTable('zatca_egs_units') && class_exists(ZatcaAPI::class);
    }

    public function environment(): string
    {
        $env = (string) config('services.zatca.env', env('ZATCA_ENV', 'production'));

        return in_array($env, ['sandbox', 'simulation', 'production'], true) ? $env : 'production';
    }

    public function api(): ZatcaAPI
    {
        $client = app()->bound('zatca.http') ? app('zatca.http') : null;

        return new ZatcaAPI($this->environment(), $client instanceof ClientInterface ? $client : null);
    }

    public function unit(Store $store): ?ZatcaEgsUnit
    {
        return $this->ready() ? ZatcaEgsUnit::query()->where('store_id', $store->id)->first() : null;
    }

    /** The unit that signs this store's invoices, or null when the store is still on phase 1. */
    public function activeUnit(Store|int|null $store): ?ZatcaEgsUnit
    {
        if (! $this->ready() || ! $store) {
            return null;
        }
        $unit = ZatcaEgsUnit::query()->where('store_id', $store instanceof Store ? $store->id : $store)->first();

        return $unit?->isActive() ? $unit : null;
    }

    // ------------------------------------------------------------------ profile

    public function profileRules(): array
    {
        return [
            'seller_name' => ['required', 'string', 'max:120'],
            'branch_name' => ['nullable', 'string', 'max:80'],
            'id_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::ID_TYPES))],
            'id_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'street' => ['required', 'string', 'max:100'],
            'building_number' => ['required', 'digits:4'],
            'district' => ['required', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:60'],
            'postal_code' => ['required', 'digits:5'],
        ];
    }

    public function saveProfile(Store $store, array $data): ZatcaEgsUnit
    {
        $unit = $this->unit($store) ?? new ZatcaEgsUnit([
            'store_id' => $store->id, 'environment' => $this->environment(), 'status' => 'draft', 'device_uuid' => (string) Str::uuid(),
        ]);
        $unit->fill(array_intersect_key($data, $this->profileRules()));
        $unit->vat_number = $store->vat_number;
        $unit->save();

        return $unit;
    }

    public function payload(Store $store): array
    {
        $unit = $this->unit($store);
        $docs = $unit ? ZatcaDocument::query()->where('egs_unit_id', $unit->id) : null;

        return [
            'available' => $this->ready(),
            'environment' => $this->environment(),
            'status' => $unit?->status ?? 'none',
            'active' => (bool) $unit?->isActive(),
            'vat_number' => $store->vat_number,
            'id_types' => collect(self::ID_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'profile' => [
                'seller_name' => $unit?->seller_name ?? ($store->tax_trade_name ?: $store->name_ar),
                'branch_name' => $unit?->branch_name,
                'id_type' => $unit?->id_type ?? 'CRN',
                'id_number' => $unit?->id_number,
                'street' => $unit?->street,
                'building_number' => $unit?->building_number,
                'district' => $unit?->district,
                'city' => $unit?->city,
                'postal_code' => $unit?->postal_code,
            ],
            'invoices_count' => (int) ($unit?->icv ?? 0),
            'pending' => $docs ? (clone $docs)->where('status', 'pending')->count() : 0,
            'rejected' => $docs ? (clone $docs)->where('status', 'rejected')->count() : 0,
            'problems' => $docs ? (clone $docs)->whereIn('status', ['rejected'])->orWhere(fn ($q) => $q->where('egs_unit_id', $unit->id)->where('status', 'pending')->where('attempts', '>=', 3))
                ->latest('id')->limit(10)->get()->map(fn (ZatcaDocument $d) => [
                    'id' => $d->id, 'number' => $d->number, 'kind' => $d->kind, 'status' => $d->status,
                    'attempts' => $d->attempts, 'errors' => $d->errors, 'created_at' => $d->created_at?->toISOString(),
                ])->values() : [],
            'last_error' => $unit?->last_error,
            'onboarded_at' => $unit?->onboarded_at?->toISOString(),
            'certificate_expires_at' => $unit?->certificate_expires_at?->toISOString(),
        ];
    }

    // ------------------------------------------------------------------ onboarding

    /**
     * Registers the store with Fatoora using the OTP the owner generated on the Fatoora
     * portal: CSR → compliance CSID → the six compliance invoices → production CSID.
     */
    public function onboard(Store $store, string $otp): ZatcaEgsUnit
    {
        $unit = $this->unit($store);
        abort_unless($store->vat_number, 422, 'أضف الرقم الضريبي في «الضريبة والفواتير» أول.');
        abort_unless($unit && $this->profileComplete($unit), 422, 'كمّل بيانات المنشأة والعنوان الوطني أول.');
        abort_if($unit->isActive() && $unit->environment === $this->environment(), 422, 'متجرك مربوط بمنصة فاتورة.');
        abort_if(ZatcaDocument::query()->where('egs_unit_id', $unit->id)->where('status', 'pending')->exists(), 422,
            'فيه فواتير ما انرسلت للهيئة للحين. انتظر لين تنرسل ثم أعد الربط.');

        $env = $this->environment();
        $unit->forceFill(['environment' => $env, 'vat_number' => $store->vat_number, 'last_error' => null])->save();
        try {
            [$csr, $privateKey] = $this->makeCsr($unit, $env);
            $api = $this->api();
            $cc = $api->requestComplianceCertificate($csr, trim($otp));
            abort_if($cc->getCertificate() === '' || $cc->getSecret() === '', 422, 'رمز التحقق غير صحيح أو انتهى. اطلب رمز جديد من بوابة فاتورة.');
            $unit->forceFill([
                'csr' => $csr, 'private_key' => $privateKey,
                'compliance_certificate' => $cc->getCertificate(), 'compliance_secret' => $cc->getSecret(),
                'compliance_request_id' => (string) $cc->getRequestId(),
            ])->save();

            $this->runComplianceChecks($unit, $api);

            $pc = $api->requestProductionCertificate($cc->getCertificate(), $cc->getSecret(), (string) $cc->getRequestId());
            abort_if($pc->getCertificate() === '' || $pc->getSecret() === '', 422, 'ما قدرنا ناخذ شهادة الإنتاج من الهيئة. حاول مرة ثانية.');
            $expires = null;
            try {
                $x509 = (new Certificate($pc->getCertificate(), $privateKey, $pc->getSecret()))->getX509();
                $validTo = $x509->getCurrentCert()['tbsCertificate']['validity']['notAfter'] ?? null;
                $expires = $validTo ? Carbon::parse(reset($validTo)) : null;
            } catch (\Throwable) {
            }
            $unit->forceFill([
                'certificate' => $pc->getCertificate(), 'secret' => $pc->getSecret(),
                'production_request_id' => (string) $pc->getRequestId(), 'certificate_expires_at' => $expires,
                'status' => 'active', 'onboarded_at' => now(),
                // A new certificate starts a new chain only for a brand-new unit.
                'icv' => $unit->icv ?: 0, 'last_hash' => $unit->last_hash ?: self::INITIAL_PIH,
            ])->save();
        } catch (ZatcaApiException $e) {
            $message = $this->apiMessage($e);
            $unit->forceFill(['status' => $unit->certificate ? $unit->status : 'failed', 'last_error' => $message])->save();
            abort(422, $message);
        }

        return $unit->refresh();
    }

    public function profileComplete(ZatcaEgsUnit $unit): bool
    {
        foreach (['seller_name', 'id_type', 'id_number', 'street', 'building_number', 'district', 'city', 'postal_code'] as $k) {
            if (blank($unit->{$k})) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: string, 1: string} CSR (PEM) and private key (PEM) */
    private function makeCsr(ZatcaEgsUnit $unit, string $env): array
    {
        $builder = (new CertificateBuilder)
            ->setOrganizationIdentifier((string) $unit->vat_number)
            ->setSerialNumber('ZadSync', 'Cashier', (string) $unit->device_uuid)
            ->setCommonName('ZadSync-'.$unit->store_id.'-'.$unit->vat_number)
            ->setCountryName('SA')
            ->setOrganizationName((string) $unit->seller_name)
            ->setOrganizationalUnitName($this->orgUnit($unit))
            ->setAddress(trim($unit->building_number.' '.$unit->street.', '.$unit->city))
            ->setInvoiceType('1100')
            ->setEnvironment(match ($env) {
                'production' => CertificateBuilder::ENV_PRODUCTION,
                'simulation' => CertificateBuilder::ENV_SIMULATION,
                default => CertificateBuilder::ENV_NONPROD,
            })
            ->setBusinessCategory('Food');
        $builder->generate();
        $path = tempnam(sys_get_temp_dir(), 'zk');
        try {
            $builder->savePrivateKey($path);
            $key = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return [$builder->getCsr(), $key];
    }

    /** VAT groups (11th digit 1) must put the member's 10-digit TIN in OU; others use the branch name. */
    private function orgUnit(ZatcaEgsUnit $unit): string
    {
        $vat = (string) $unit->vat_number;
        if (strlen($vat) === 15 && $vat[10] === '1') {
            return substr($vat, 0, 10);
        }

        return $unit->branch_name ?: (string) $unit->seller_name;
    }

    /** The six documents ZATCA checks before giving a production certificate (type 1100). */
    private function runComplianceChecks(ZatcaEgsUnit $unit, ZatcaAPI $api): void
    {
        $cert = new Certificate((string) $unit->compliance_certificate, $this->cleanKey((string) $unit->private_key), (string) $unit->compliance_secret);
        $pih = self::INITIAL_PIH;
        $icv = 0;
        $customer = ['name' => 'شركة نماذج فاتورة المحدودة', 'vat' => '399999999800003', 'street' => 'صلاح الدين',
            'building' => '1111', 'district' => 'المروج', 'city' => 'الرياض', 'postal' => '12222'];
        $now = now('Asia/Riyadh');
        $base = ['lines' => [['name' => 'منتج تجريبي', 'quantity' => 1, 'unit_price' => 10.0]], 'discount' => 0.0, 'payment' => 'cash', 'issued_at' => $now];
        $plans = [
            ['standard', 'invoice', 'CMP-0001', null],
            ['standard', 'credit', 'CMP-0002', 'CMP-0001'],
            ['standard', 'debit', 'CMP-0003', 'CMP-0001'],
            ['simplified', 'invoice', 'CMP-0004', null],
            ['simplified', 'credit', 'CMP-0005', 'CMP-0004'],
            ['simplified', 'debit', 'CMP-0006', 'CMP-0004'],
        ];
        foreach ($plans as [$type, $kind, $number, $ref]) {
            $icv++;
            $uuid = (string) Str::uuid();
            $xml = $this->xml($unit, $base + [
                'uuid' => $uuid, 'number' => $number, 'type' => $type, 'kind' => $kind, 'icv' => $icv, 'pih' => $pih,
                'customer' => $type === 'standard' ? $customer : null,
                'billing_ref' => $ref, 'reason' => $ref ? 'إلغاء أو تعديل الفاتورة' : null,
            ]);
            $signed = InvoiceSigner::signInvoice($xml, $cert);
            $res = $api->validateInvoiceCompliance((string) $unit->compliance_certificate, (string) $unit->compliance_secret,
                $signed->getInvoice(), $signed->getHash(), $uuid);
            $errors = $res->getErrorMessages();
            if (! ($res->isSuccess() || $res->getStatusCode() === 202) || ! empty($errors)) {
                $first = $errors[0]['message'] ?? ($res->getValidationStatus() ?? 'رفض');
                Log::warning('ZATCA compliance check failed', ['store' => $unit->store_id, 'doc' => $number, 'errors' => $errors]);
                abort(422, 'الهيئة رفضت فحص الامتثال ('.$number.'): '.$first);
            }
            $pih = $signed->getHash();
        }
    }

    // ------------------------------------------------------------------ documents

    /** Signs the invoice of a VAT sale when the store is linked to Fatoora. */
    public function issueForReceipt(SaleReceipt $receipt): ?ZatcaDocument
    {
        $unit = $this->activeUnit($receipt->store_id);
        if (! $unit || ! $receipt->seller_vat_number) {
            return null;
        }
        $standard = (bool) $receipt->customer_vat_number;

        return $this->issue($unit, [
            'sale_receipt_id' => $receipt->id,
            'kind' => 'invoice',
            'type' => $standard ? 'standard' : 'simplified',
            'number' => (string) $receipt->number,
            'issued_at' => $receipt->issued_at ?? now(),
            'lines' => array_map(fn ($i) => ['name' => (string) $i['name'], 'quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price']], $receipt->items ?? []),
            'discount' => (float) $receipt->discount,
            'payment' => (string) $receipt->payment_method,
            'customer' => $standard ? $this->customerOf($receipt) : null,
        ]);
    }

    /** A return against a signed invoice becomes a credit note in the same chain. */
    public function issueForReturn(SaleReturn $return): ?ZatcaDocument
    {
        $receipt = $return->receipt;
        $original = $receipt ? ZatcaDocument::query()->where('sale_receipt_id', $receipt->id)->where('kind', 'invoice')->first() : null;
        $unit = $this->activeUnit($return->store_id);
        if (! $unit || ! $original) {
            return null;
        }

        return $this->issue($unit, [
            'sale_receipt_id' => $receipt->id,
            'sale_return_id' => $return->id,
            'kind' => 'credit',
            'type' => $original->invoice_type,
            'number' => (string) $return->number,
            'issued_at' => $return->issued_at ?? now(),
            'lines' => array_map(fn ($i) => ['name' => (string) $i['name'], 'quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price']], $return->items ?? []),
            'discount' => (float) $return->discount,
            'payment' => (string) $return->refund_method,
            'customer' => $original->invoice_type === 'standard' ? $this->customerOf($receipt) : null,
            'billing_ref' => $original->number,
            'reason' => (string) $return->reason,
        ]);
    }

    private function issue(ZatcaEgsUnit $unit, array $o): ZatcaDocument
    {
        return DB::transaction(function () use ($unit, $o): ZatcaDocument {
            /** @var ZatcaEgsUnit $locked */
            $locked = ZatcaEgsUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $icv = (int) $locked->icv + 1;
            $pih = $locked->last_hash ?: self::INITIAL_PIH;
            $uuid = (string) Str::uuid();
            $xml = $this->xml($locked, $o + ['uuid' => $uuid, 'icv' => $icv, 'pih' => $pih]);
            $cert = new Certificate((string) $locked->certificate, $this->cleanKey((string) $locked->private_key), (string) $locked->secret);
            $signed = InvoiceSigner::signInvoice($xml, $cert);
            $doc = ZatcaDocument::query()->create([
                'store_id' => $locked->store_id, 'egs_unit_id' => $locked->id,
                'sale_receipt_id' => $o['sale_receipt_id'] ?? null, 'sale_return_id' => $o['sale_return_id'] ?? null,
                'kind' => $o['kind'], 'invoice_type' => $o['type'], 'icv' => $icv, 'uuid' => $uuid, 'number' => $o['number'],
                'invoice_hash' => $signed->getHash(), 'previous_hash' => $pih,
                'signed_xml' => $signed->getInvoice(), 'qr' => $signed->getQRCode(), 'status' => 'pending',
            ]);
            $locked->forceFill(['icv' => $icv, 'last_hash' => $signed->getHash()])->save();

            return $doc;
        });
    }

    /** Sends one document: clearance for standard (B2B), reporting for simplified (B2C). */
    public function submit(ZatcaDocument $doc): ZatcaDocument
    {
        if (! in_array($doc->status, ['pending'], true)) {
            return $doc;
        }
        $unit = $doc->unit;
        if (! $unit || ! $unit->isActive()) {
            return $doc;
        }
        $api = $this->api();
        $doc->forceFill(['attempts' => $doc->attempts + 1, 'submitted_at' => now()])->save();
        try {
            if ($doc->invoice_type === 'standard') {
                $res = $api->submitClearanceInvoice((string) $unit->certificate, (string) $unit->secret, $doc->signed_xml, $doc->invoice_hash, $doc->uuid);
                $ok = $res->isCleared();
                $update = ['status' => $ok ? 'cleared' : 'rejected'];
                $cleared = $ok ? $res->getDecodedClearedInvoice() : null;
                if ($cleared) {
                    $update['signed_xml'] = $cleared;
                    $qr = $this->qrFromXml($cleared);
                    if ($qr) {
                        $update['qr'] = $qr;
                    }
                }
            } else {
                $res = $api->submitReportingInvoice((string) $unit->certificate, (string) $unit->secret, $doc->signed_xml, $doc->invoice_hash, $doc->uuid);
                $ok = $res->isReported();
                $update = ['status' => $ok ? 'reported' : 'rejected'];
            }
            $doc->forceFill($update + [
                'warnings' => $res->getWarningMessages() ?: null,
                'errors' => $res->getErrorMessages() ?: null,
                'next_attempt_at' => null,
            ])->save();
        } catch (ZatcaApiException $e) {
            $ctx = $e->getContext();
            $status = (int) ($ctx['status_code'] ?? $ctx['status'] ?? 0);
            $body = $ctx['response'] ?? null;
            $errors = is_array($body) ? ($body['validationResults']['errorMessages'] ?? []) : [];
            if ($status === 400 && $errors) {
                $doc->forceFill(['status' => 'rejected', 'errors' => $errors, 'next_attempt_at' => null])->save();
            } else {
                // Network / ZATCA down / auth: try again later (the 24-hour window is long).
                $doc->forceFill(['next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $doc->attempts))),
                    'errors' => [['message' => $this->apiMessage($e)]]])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('ZATCA submit failed', ['doc' => $doc->id, 'error' => $e->getMessage()]);
            $doc->forceFill(['next_attempt_at' => now()->addMinutes(5), 'errors' => [['message' => 'تعذر الاتصال بالهيئة']]])->save();
        }

        return $doc->refresh();
    }

    /** Scheduler: sends everything still pending, oldest first within each store's chain. */
    public function submitPending(int $limit = 100): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $this->signMissing();
        $docs = ZatcaDocument::query()->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('egs_unit_id')->orderBy('icv')->limit($limit)->get();
        foreach ($docs as $doc) {
            $this->submit($doc);
        }

        return $docs->count();
    }

    /** VAT sales of linked stores that were not signed at sale time (e.g. a transient error). */
    public function signMissing(int $limit = 50): int
    {
        $count = 0;
        foreach (ZatcaEgsUnit::query()->where('status', 'active')->whereNotNull('onboarded_at')->get() as $unit) {
            $receipts = SaleReceipt::query()->where('store_id', $unit->store_id)->whereNotNull('seller_vat_number')
                ->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM)->where('created_at', '>=', $unit->onboarded_at)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('zatca_documents')
                    ->whereColumn('zatca_documents.sale_receipt_id', 'sale_receipts.id')->where('zatca_documents.kind', 'invoice'))
                ->orderBy('id')->limit($limit)->get();
            foreach ($receipts as $receipt) {
                try {
                    $this->issueForReceipt($receipt);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error('ZATCA late signing failed', ['receipt' => $receipt->id, 'error' => $e->getMessage()]);
                    $unit->forceFill(['last_error' => 'تعذر توقيع فاتورة '.$receipt->number])->save();
                    break;
                }
            }
        }

        return $count;
    }

    /** Phase-2 QR of the receipt's invoice, when it was signed. */
    public function qrFor(SaleReceipt $receipt): ?string
    {
        if (! $this->ready()) {
            return null;
        }

        return ZatcaDocument::query()->where('sale_receipt_id', $receipt->id)->where('kind', 'invoice')->value('qr');
    }

    public function documentFor(SaleReceipt $receipt): ?ZatcaDocument
    {
        return $this->ready() ? ZatcaDocument::query()->where('sale_receipt_id', $receipt->id)->where('kind', 'invoice')->first() : null;
    }

    // ------------------------------------------------------------------ XML

    /**
     * UBL 2.1 invoice / credit / debit note. Prices are VAT-exclusive; VAT is 15% on the
     * net after the document discount, the same maths the cashier uses for the total.
     */
    public function xml(ZatcaEgsUnit $unit, array $o): string
    {
        $issued = ($o['issued_at'] instanceof CarbonInterface ? $o['issued_at'] : Carbon::parse($o['issued_at']))->copy()->timezone('Asia/Riyadh');
        $lines = [];
        $lineSum = 0.0;
        foreach (array_values($o['lines']) as $n => $l) {
            $ext = round($l['quantity'] * $l['unit_price'], 2);
            $tax = round($ext * self::VAT_RATE / 100, 2);
            $lineSum += $ext;
            $lines[] = [
                'id' => $n + 1, 'unitCode' => 'PCE', 'quantity' => $l['quantity'], 'lineExtensionAmount' => $ext,
                'item' => ['name' => mb_substr($l['name'], 0, 120), 'classifiedTaxCategory' => [['percent' => self::VAT_RATE, 'taxScheme' => ['id' => 'VAT']]]],
                'price' => ['amount' => round($l['unit_price'], 2), 'unitCode' => 'UNIT'],
                'taxTotal' => ['taxAmount' => $tax, 'roundingAmount' => round($ext + $tax, 2)],
            ];
        }
        $lineSum = round($lineSum, 2);
        $discount = round(min(max(0, (float) ($o['discount'] ?? 0)), $lineSum), 2);
        $net = round($lineSum - $discount, 2);
        $vat = round($net * self::VAT_RATE / 100, 2);
        $data = [
            'uuid' => $o['uuid'],
            'id' => $o['number'],
            'issueDate' => $issued->format('Y-m-d'),
            'issueTime' => $issued->format('H:i:s'),
            'currencyCode' => 'SAR',
            'taxCurrencyCode' => 'SAR',
            'invoiceType' => [
                'invoice' => $o['type'], 'type' => $o['kind'],
                'isThirdParty' => false, 'isNominal' => false, 'isExport' => false, 'isSummary' => false, 'isSelfBilled' => false,
            ],
            'additionalDocuments' => [
                ['id' => 'ICV', 'uuid' => (string) $o['icv']],
                ['id' => 'PIH', 'attachment' => ['content' => $o['pih']]],
            ],
            'supplier' => [
                'registrationName' => (string) $unit->seller_name,
                'taxId' => (string) $unit->vat_number,
                'identificationId' => (string) $unit->id_number,
                'identificationType' => (string) $unit->id_type,
                'address' => [
                    'street' => (string) $unit->street, 'buildingNumber' => (string) $unit->building_number,
                    'subdivision' => (string) $unit->district, 'city' => (string) $unit->city,
                    'postalZone' => (string) $unit->postal_code, 'country' => 'SA',
                ],
            ],
            'paymentMeans' => ['code' => self::PAYMENT_CODES[$o['payment']] ?? '10'],
            'allowanceCharges' => [[
                'isCharge' => false, 'reason' => 'discount', 'amount' => $discount,
                'taxCategories' => [['percent' => self::VAT_RATE, 'taxScheme' => ['id' => 'VAT']]],
            ]],
            'taxTotal' => [
                'taxAmount' => $vat,
                'subTotals' => [['taxableAmount' => $net, 'taxAmount' => $vat, 'taxCategory' => ['percent' => self::VAT_RATE, 'taxScheme' => ['id' => 'VAT']]]],
            ],
            'legalMonetaryTotal' => [
                'lineExtensionAmount' => $lineSum, 'taxExclusiveAmount' => $net, 'taxInclusiveAmount' => round($net + $vat, 2),
                'prepaidAmount' => 0, 'payableAmount' => round($net + $vat, 2), 'allowanceTotalAmount' => $discount,
            ],
            'invoiceLines' => $lines,
        ];
        if (! empty($o['billing_ref'])) {
            $data['billingReferences'] = [['id' => $o['billing_ref']]];
            $data['paymentMeans']['note'] = mb_substr((string) ($o['reason'] ?: 'مرتجع'), 0, 200);
        }
        if (! empty($o['customer'])) {
            $c = $o['customer'];
            $data['customer'] = [
                'registrationName' => $c['name'],
                'taxId' => $c['vat'],
                'address' => [
                    'street' => $c['street'], 'buildingNumber' => $c['building'], 'subdivision' => $c['district'],
                    'city' => $c['city'], 'postalZone' => $c['postal'], 'country' => 'SA',
                ],
            ];
        }
        if ($o['type'] === 'standard') {
            $data['delivery'] = ['actualDeliveryDate' => $issued->format('Y-m-d')];
        }

        return GeneratorInvoice::invoice((new InvoiceMapper())->mapToInvoice($data))->getXML();
    }

    private function customerOf(SaleReceipt $receipt): array
    {
        return [
            'name' => (string) ($receipt->customer_name ?: 'عميل'), 'vat' => (string) $receipt->customer_vat_number,
            'street' => (string) $receipt->customer_street, 'building' => (string) $receipt->customer_building,
            'district' => (string) $receipt->customer_district, 'city' => (string) $receipt->customer_city,
            'postal' => (string) $receipt->customer_postal,
        ];
    }

    private function cleanKey(string $pem): string
    {
        return trim((string) preg_replace('/-----(?:BEGIN|END)(?: EC)? PRIVATE KEY-----|\s+/', '', $pem));
    }

    private function qrFromXml(string $xml): ?string
    {
        if (preg_match('#<cbc:ID>QR</cbc:ID>\s*<cac:Attachment>\s*<cbc:EmbeddedDocumentBinaryObject[^>]*>([^<]+)</cbc:EmbeddedDocumentBinaryObject>#', $xml, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function apiMessage(ZatcaApiException $e): string
    {
        $ctx = $e->getContext();
        $body = $ctx['response'] ?? null;
        $msg = is_array($body)
            ? ($body['validationResults']['errorMessages'][0]['message'] ?? $body['errors'][0]['message'] ?? $body['message'] ?? null)
            : (is_string($body) ? $body : null);

        return 'منصة فاتورة: '.mb_substr((string) ($msg ?: $e->getMessage()), 0, 300);
    }
}
