<?php

namespace App\Services\App;

use App\Models\FamilyStorePurchase;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

class FamilySubscriptionReceiptService
{
    public function settings(): array
    {
        return array_replace([
            'issuer_name' => 'زاد سينك',
            'heading' => 'فاتورة اشتراك زاد سينك',
            'intro' => 'تم سداد اشتراك متجر الأسرة عبر ميسر.',
            'footer' => 'صدرت هذه الفاتورة بعد تأكيد عملية السداد لدى ميسر.',
            'accent_color' => '#9A3412',
            'layout' => 'classic',
            'logo_path' => null,
            'image_path' => null,
            'stamp_path' => null,
        ], PlatformControl::cachedValues()['family_subscription_receipt'] ?? []);
    }

    /** Called inside the payment/grant transaction. Historical receipts never follow later edits. */
    public function issue(FamilyStorePurchase $purchase, string $paymentId): void
    {
        if ($purchase->receipt_snapshot) {
            return;
        }

        $family = ProductiveFamily::query()->findOrFail($purchase->productive_family_id);
        $store = Store::query()->where('productive_family_id', $family->id)->first();
        $grant = $purchase->grant_id ? \App\Models\FamilyStoreGrant::query()->findOrFail($purchase->grant_id)
            : \App\Models\FamilyCashierGrant::query()->where('purchase_id', $purchase->id)->first();
        $number = 'ZAD-SUB-'.now('Asia/Riyadh')->format('Y').'-'.str_pad((string) $purchase->id, 8, '0', STR_PAD_LEFT);
        $settings = $this->settings();
        $snapshot = [
            'number' => $number,
            'issuer_name' => (string) $settings['issuer_name'],
            'heading' => (string) $settings['heading'],
            'intro' => (string) $settings['intro'],
            'footer' => (string) $settings['footer'],
            'accent_color' => (string) $settings['accent_color'],
            'layout' => (string) $settings['layout'],
            'logo_path' => $settings['logo_path'],
            'image_path' => $settings['image_path'],
            'stamp_path' => $settings['stamp_path'] ?? null,
            'issued_at' => now()->toIso8601String(),
            'family_id' => $family->id,
            'family_name' => (string) $family->owner_name,
            'store_name' => (string) ($store?->name_ar ?? 'متجر الأسرة'),
            'plan_code' => $purchase->plan_code,
            'plan_name' => (string) (\App\Models\FamilyStorePlan::query()->where('code', $purchase->plan_code)->value('name') ?? $purchase->plan_code),
            'amount_halalas' => $purchase->amount_halalas,
            'original_amount_halalas' => $purchase->original_amount_halalas ?? $purchase->amount_halalas,
            'discount_halalas' => (int) $purchase->discount_halalas,
            'coupon_code' => $purchase->coupon_code,
            'currency' => $purchase->currency,
            'paid_at' => $purchase->paid_at?->toIso8601String(),
            'starts_at' => $grant?->starts_at?->toIso8601String(),
            'ends_at' => $grant?->ends_at?->toIso8601String(),
            'duration_days' => $purchase->duration_days,
            'moyasar_payment_id' => $paymentId,
            'payment_mode' => $purchase->mode,
        ];
        $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $purchase->update(['receipt_number' => $number, 'receipt_snapshot' => $snapshot, 'receipt_hash' => $hash]);
    }

    public function pdf(FamilyStorePurchase $purchase): string
    {
        abort_unless($purchase->status === 'paid' && $purchase->receipt_snapshot, 404);
        return DB::transaction(function () use ($purchase): string {
            $locked = FamilyStorePurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->receipt_pdf_path) {
                $bytes = Storage::disk('r2_contracts')->get($locked->receipt_pdf_path);
                abort_unless(is_string($bytes) && $bytes !== '', 503, 'تعذر قراءة الإيصال المؤرشف.');
                abort_unless(hash('sha256', $bytes) === $locked->receipt_pdf_sha256, 500, 'فشل التحقق من نسخة الإيصال المؤرشفة.');
                return $bytes;
            }
            $temp = storage_path('app/private/family-subscription-mpdf');
            File::ensureDirectoryExists($temp);
            $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp,
                'autoScriptToLang' => true, 'autoLangToFont' => true]);
            $pdf->SetDirectionality('rtl');
            $pdf->SetTitle('إيصال اشتراك '.$locked->receipt_number);
            $snapshot = $locked->receipt_snapshot;
            $images = [];
            foreach (['logo', 'image', 'stamp'] as $kind) {
                $path = $snapshot[$kind.'_path'] ?? null;
                if ($path) {
                    $bytes = Storage::disk('r2_contracts')->get($path);
                    abort_unless(is_string($bytes) && $bytes !== '', 503, 'تعذر قراءة صورة الإيصال المؤرشفة.');
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                    abort_unless(in_array($mime, ['image/png', 'image/jpeg'], true), 500);
                    $pdf->imageVars[$kind] = $bytes;
                    $images[$kind] = 'var:'.$kind;
                }
            }
            if (empty($images['logo'])) {
                $defaultLogo = public_path('app/assets/assets/contracts/zad_logo.png');
                if (File::isFile($defaultLogo)) {
                    $pdf->imageVars['logo'] = File::get($defaultLogo);
                    $images['logo'] = 'var:logo';
                }
            }
            if (empty($images['stamp'])) {
                $defaultStamp = public_path('app/assets/assets/contracts/zad_seal.png');
                if (File::isFile($defaultStamp)) {
                    $pdf->imageVars['stamp'] = File::get($defaultStamp);
                    $images['stamp'] = 'var:stamp';
                }
            }
            $pdf->WriteHTML(view('family-subscriptions.receipt', ['receipt' => $locked->receipt_snapshot,
                'fingerprint' => $locked->receipt_hash, 'images' => $images,
                'verifyUrl' => $this->verifyUrl($locked->receipt_number, (string) $locked->receipt_hash)])->render());
            $bytes = $pdf->Output('', 'S');
            $path = 'family-subscription-receipts/'.$locked->id.'.pdf';
            abort_unless(Storage::disk('r2_contracts')->put($path, $bytes), 500, 'تعذر أرشفة الإيصال في مخزن العقود الآمن.');
            $locked->update(['receipt_pdf_path' => $path, 'receipt_pdf_sha256' => hash('sha256', $bytes)]);
            return $bytes;
        }, 3);
    }

    public function verifyUrl(?string $number, string $hash): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/family-subscription-receipts/verify?'
            .http_build_query(['number' => (string) $number, 'fp' => substr($hash, 0, 16)]);
    }
}
