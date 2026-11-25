<?php

namespace App\Services\App;

use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\FamilyStorePurchase;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyStorePurchaseService
{
    public function __construct(
        private readonly MoyasarModeResolver $modes,
        private readonly PlatformOperationGate $operations,
    ) {}

    public function available(): bool
    {
        $control = PlatformControl::cachedValues()['family_subscriptions'] ?? [];
        if (! ($control['enabled'] ?? false)) {
            return false;
        }

        try {
            $mode = $this->modes->currentMode();
            $this->modes->credentials($mode);
            if ($mode === 'live' && ! str_starts_with((string) config('app.url'), 'https://')) {
                return false;
            }
            return in_array($mode, ['live', 'sandbox'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function start(ProductiveFamily $family, int $userId, string $code): FamilyStorePurchase
    {
        abort_unless($this->available(), 503, 'شراء الباقات غير متاح حالياً.');
        $this->operations->assertNewPaymentAttemptAllowed();
        $mode = $this->modes->currentMode();
        $secret = $this->modes->credentials($mode)['secret_key'];
        $plan = FamilyStorePlan::query()->where('code', $code)->where('is_visible', true)->firstOrFail();
        abort_unless(in_array($code, ['silver', 'gold'], true), 422);
        $amount = $this->halalas((string) $plan->price);
        abort_unless($amount >= 100 && $plan->duration_days >= 1 && $plan->duration_days <= 3650, 422);

        $base = rtrim((string) config('app.url'), '/');
        if ($mode === 'live') {
            abort_unless(str_starts_with($base, 'https://'), 503, 'رابط الخدمة الآمن غير مضبوط.');
        }

        $token = Str::random(64);
        $purchase = DB::transaction(function () use ($family, $userId, $plan, $amount, $mode, $token): FamilyStorePurchase {
            ProductiveFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $previous = FamilyStorePurchase::query()->where('productive_family_id', $family->id)
                ->whereIn('status', ['creating', 'unknown', 'pending'])->latest('id')->lockForUpdate()->first();
            if ($previous && $previous->expires_at->isFuture()) {
                if ($previous->status === 'pending' && $previous->plan_code === $plan->code && $previous->checkout_url) {
                    return $previous;
                }
                abort(409, 'لديك فاتورة مفتوحة. أكملها أو انتظر انتهاءها قبل اختيار باقة أخرى.');
            }
            if ($previous && $previous->status !== 'paid') {
                $previous->update(['status' => 'expired']);
            }

            return FamilyStorePurchase::query()->create([
                'productive_family_id' => $family->id,
                'created_by' => $userId,
                'plan_code' => $plan->code,
                'amount_halalas' => $amount,
                'duration_days' => $plan->duration_days,
                'currency' => 'SAR',
                'mode' => $mode,
                'status' => 'creating',
                'callback_token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(25),
            ]);
        }, 3);

        if ($purchase->status === 'pending') {
            return $purchase;
        }

        $callback = $base.'/api/v1/app/family-store-purchases/callback/'.$token;

        try {
            $response = Http::acceptJson()->withBasicAuth($secret, '')->timeout(20)
                ->post('https://api.moyasar.com/v1/invoices', [
                    'amount' => $purchase->amount_halalas,
                    'currency' => 'SAR',
                    'description' => 'ZAD Sync family store '.$purchase->plan_code.' #'.$purchase->id,
                    'callback_url' => $callback,
                    'expired_at' => $purchase->expires_at->toISOString(),
                ]);
        } catch (ConnectionException) {
            $purchase->update(['status' => 'unknown']);
            throw ValidationException::withMessages(['payment' => ['تعذر الاتصال بميسر. انتظر انتهاء الفاتورة قبل إعادة المحاولة.']]);
        }

        if (! $response->created()) {
            $purchase->update(['status' => $response->status() >= 500 ? 'unknown' : 'failed']);
            throw ValidationException::withMessages(['payment' => ['تعذر إنشاء فاتورة الاشتراك لدى ميسر.']]);
        }

        $invoice = $response->json();
        $id = is_array($invoice) ? (string) ($invoice['id'] ?? '') : '';
        $url = is_array($invoice) ? (string) ($invoice['url'] ?? '') : '';
        if (! Str::isUuid($id) || ! $this->validInvoiceUrl($url)
            || ! str_contains((string) parse_url($url, PHP_URL_PATH), $id)
            || (int) ($invoice['amount'] ?? 0) !== $purchase->amount_halalas
            || ($invoice['currency'] ?? '') !== 'SAR') {
            $purchase->update(['status' => 'unknown']);
            throw ValidationException::withMessages(['payment' => ['فاتورة ميسر غير متطابقة؛ تواصل مع الدعم قبل إعادة المحاولة.']]);
        }
        $purchase->update(['invoice_id' => $id, 'checkout_url' => $url, 'status' => 'pending']);

        return $purchase;
    }

    public function reconcile(FamilyStorePurchase $purchase): FamilyStorePurchase
    {
        if (! $purchase->invoice_id || ! in_array($purchase->status, ['pending', 'expired'], true)) {
            return $purchase->fresh();
        }
        $secret = $this->modes->credentials($purchase->mode)['secret_key'];
        try {
            $response = Http::acceptJson()->withBasicAuth($secret, '')->timeout(20)
                ->get('https://api.moyasar.com/v1/invoices/'.rawurlencode($purchase->invoice_id));
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payment' => ['تعذر التأكد من الدفع؛ حاول التحقق لاحقاً.']]);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw ValidationException::withMessages(['payment' => ['تعذر التحقق من فاتورة ميسر.']]);
        }
        $invoice = $response->json();
        abort_unless(($invoice['id'] ?? '') === $purchase->invoice_id
            && (int) ($invoice['amount'] ?? 0) === $purchase->amount_halalas
            && ($invoice['currency'] ?? '') === $purchase->currency, 422, 'فاتورة ميسر غير متطابقة.');

        if (($invoice['status'] ?? '') !== 'paid') {
            if (in_array($invoice['status'] ?? '', ['failed', 'canceled', 'expired', 'voided'], true)) {
                $purchase->update(['status' => 'failed']);
            }
            return $purchase->fresh();
        }

        $paidPayment = collect($invoice['payments'] ?? [])->first(fn ($payment) =>
            is_array($payment) && ($payment['status'] ?? '') === 'paid'
            && ($payment['invoice_id'] ?? '') === $purchase->invoice_id
            && (int) ($payment['amount'] ?? 0) === $purchase->amount_halalas
            && Str::isUuid((string) ($payment['id'] ?? ''))
        );
        abort_unless($paidPayment, 422, 'لم يُتحقق من دفعة الفاتورة لدى ميسر.');

        DB::transaction(function () use ($purchase, $paidPayment): void {
            ProductiveFamily::query()->whereKey($purchase->productive_family_id)->lockForUpdate()->firstOrFail();
            $locked = FamilyStorePurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->paid_at || $locked->grant_id) {
                return;
            }
            $lastGrant = FamilyStoreGrant::query()
                ->where('productive_family_id', $locked->productive_family_id)
                ->whereNull('revoked_at')->where('ends_at', '>', now())
                ->orderByDesc('ends_at')->first();
            $start = $lastGrant?->ends_at?->isFuture() ? $lastGrant->ends_at->copy() : now();
            $grant = FamilyStoreGrant::query()->create([
                'productive_family_id' => $locked->productive_family_id,
                'plan_code' => $locked->plan_code,
                'source' => 'purchase',
                'starts_at' => $start,
                'ends_at' => $start->copy()->addDays($locked->duration_days),
                'reason' => 'Moyasar invoice '.$locked->invoice_id,
            ]);
            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_payment_id' => $paidPayment['id'],
                'grant_id' => $grant->id,
            ]);
        }, 3);

        return $purchase->fresh();
    }

    private function halalas(string $price): int
    {
        abort_unless(preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/', $price, $matches), 422);
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '0', 2, '0');
    }

    private function validInvoiceUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'checkout.moyasar.com';
    }
}
