<?php

namespace App\Services\App;

use App\Models\FamilyStorePlan;
use App\Models\FamilyStorePurchase;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyStorePurchaseService
{
    public function __construct(
        private readonly MoyasarModeResolver $modes,
        private readonly PlatformOperationGate $operations,
        private readonly FamilySubscriptionReceiptService $receipts,
        private readonly FamilyStorefrontService $storefront,
        private readonly FamilySubscriptionCouponService $coupons,
    ) {}

    /** Owner switches only. A 100% coupon may activate a plan even when Moyasar is unavailable. */
    public function purchasingEnabled(): bool
    {
        return (bool) config('zad_family_subscription_payment.enabled', false)
            && (bool) (PlatformControl::cachedValues()['family_subscriptions']['enabled'] ?? false);
    }

    public function available(): bool
    {
        if (! $this->purchasingEnabled()) {
            return false;
        }
        try {
            $mode = $this->modes->currentMode();
            $this->modes->credentials($mode);
            return in_array($mode, ['live', 'sandbox'], true)
                && ($mode !== 'live' || str_starts_with((string) config('app.url'), 'https://'));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{coupon: ?\App\Models\FamilySubscriptionCoupon, original_halalas: int, discount_halalas: int, final_halalas: int} */
    public function quote(ProductiveFamily $family, string $code, ?string $couponCode): array
    {
        $plan = FamilyStorePlan::query()->where('code', $code)->where('is_visible', true)->firstOrFail();
        abort_unless(in_array($code, ['silver', 'gold'], true), 422);
        $amount = $this->halalas((string) $plan->price);
        abort_unless($amount >= FamilySubscriptionCouponService::MIN_CHARGE_HALALAS, 422, 'سعر الباقة غير مكتمل.');

        return $this->coupons->quote($couponCode, $plan, $family, $amount);
    }

    public function start(ProductiveFamily $family, int $userId, string $code, ?string $couponCode = null): FamilyStorePurchase
    {
        abort_unless($this->purchasingEnabled(), 503, 'شراء الباقات غير متاح حالياً.');
        $plan = FamilyStorePlan::query()->where('code', $code)->where('is_visible', true)->firstOrFail();
        abort_unless(in_array($code, ['silver', 'gold'], true), 422);
        $original = $this->halalas((string) $plan->price);
        abort_unless($original >= FamilySubscriptionCouponService::MIN_CHARGE_HALALAS
            && $plan->duration_days >= 1 && $plan->duration_days <= 3650, 422);
        $couponCode = FamilySubscriptionCouponService::normalize($couponCode);
        // Validates the coupon first, so a wrong code never opens a payment attempt.
        $preview = $this->coupons->quote($couponCode, $plan, $family, $original);
        $free = $preview['final_halalas'] === 0;
        if (! $free) {
            abort_unless($this->available(), 503, 'شراء الباقات غير متاح حالياً.');
            $this->operations->assertNewPaymentAttemptAllowed();
        }
        $mode = $free ? 'coupon' : $this->modes->currentMode();

        $purchase = DB::transaction(function () use ($family, $userId, $plan, $original, $mode, $couponCode): FamilyStorePurchase {
            ProductiveFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $quote = $this->coupons->quote($couponCode, $plan, $family, $original, true);
            $previous = FamilyStorePurchase::query()->where('productive_family_id', $family->id)
                ->whereIn('status', ['pending', 'unknown'])->latest('id')->lockForUpdate()->first();
            if ($previous && $previous->expires_at->isFuture() && $previous->status === 'pending'
                && $previous->plan_code === $plan->code && $previous->amount_halalas === $quote['final_halalas']
                && $previous->mode === $mode && (string) $previous->coupon_code === $couponCode) {
                return $previous;
            }
            if ($previous) {
                // A newer choice replaces the open attempt. If that attempt was in fact paid,
                // reconciliation still activates it, so the family never loses money.
                $previous->update(['status' => 'expired']);
                $this->coupons->release($previous);
            }
            $purchase = FamilyStorePurchase::query()->create([
                'productive_family_id' => $family->id,
                'created_by' => $userId,
                'plan_code' => $plan->code,
                'amount_halalas' => $quote['final_halalas'],
                'original_amount_halalas' => $quote['original_halalas'],
                'discount_halalas' => $quote['discount_halalas'],
                'coupon_id' => $quote['coupon']?->id,
                'coupon_code' => $quote['coupon']?->code,
                'duration_days' => $plan->duration_days,
                'currency' => 'SAR',
                'mode' => $mode,
                'status' => 'pending',
                'given_id' => (string) Str::uuid(),
                // Kept to satisfy the existing schema; no hosted invoice or callback is created.
                'callback_token_hash' => hash('sha256', Str::random(64)),
                'expires_at' => now()->addHour(),
            ]);
            if ($quote['coupon']) {
                $this->coupons->reserve($quote['coupon'], $purchase, $quote['discount_halalas']);
            }

            return $purchase;
        }, 3);

        return $free ? $this->activateFree($purchase) : $purchase;
    }

    /** A 100% coupon activates the plan without contacting Moyasar. */
    private function activateFree(FamilyStorePurchase $purchase): FamilyStorePurchase
    {
        DB::transaction(function () use ($purchase): void {
            ProductiveFamily::query()->whereKey($purchase->productive_family_id)->lockForUpdate()->firstOrFail();
            $locked = FamilyStorePurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->paid_at || $locked->grant_id || $locked->amount_halalas !== 0 || $locked->mode !== 'coupon') {
                return;
            }
            $grant = $this->storefront->activatePurchase($locked->productive_family_id, $locked->plan_code,
                $locked->duration_days, 'Coupon '.$locked->coupon_code);
            $locked->update(['status' => 'paid', 'paid_at' => now(), 'grant_id' => $grant->id]);
            $this->coupons->redeem($locked);
            $this->receipts->issue($locked->fresh(), 'COUPON-'.$locked->coupon_code);
        }, 3);
        $this->receipts->pdf($purchase->fresh());

        return $purchase->fresh();
    }

    public function clientConfig(FamilyStorePurchase $purchase): array
    {
        if (! $this->available() || $purchase->status !== 'pending' || $purchase->expires_at->isPast()) {
            return [];
        }
        $credentials = $this->modes->credentials($purchase->mode);
        return [
            'publishable_key' => $credentials['publishable_key'],
            'given_id' => $purchase->given_id,
            'amount_halalas' => $purchase->amount_halalas,
            'currency' => $purchase->currency,
            'description' => 'ZAD Sync family store subscription '.$purchase->plan_code.' #'.$purchase->id,
            'metadata' => [
                'family_store_purchase_id' => (string) $purchase->id,
                'productive_family_id' => (string) $purchase->productive_family_id,
                'environment' => $purchase->mode,
            ],
            'apple_pay_merchant_id' => trim((string) config('moyasar.apple_pay_merchant_id', '')),
            'apple_pay_label' => (string) config('moyasar.apple_pay_label', 'ZAD Sync'),
            'web_checkout_available' => $purchase->mode === 'live'
                && (bool) config('zad_family_subscription_payment.enabled', false),
        ];
    }

    public function webIntent(FamilyStorePurchase $purchase): array
    {
        abort_unless($this->available() && $purchase->mode === 'live'
            && (bool) config('zad_family_subscription_payment.enabled', false), 503);
        $this->operations->assertNewPaymentAttemptAllowed();
        $secret = $this->modes->credentials('live')['secret_key'];
        $callback = 'https://zad-sync-sa.onrender.com/zad-family-subscription-return.html';

        $state = DB::transaction(function () use ($purchase): array {
            ProductiveFamily::query()->whereKey($purchase->productive_family_id)->lockForUpdate()->firstOrFail();
            $locked = FamilyStorePurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 409);
            if ($locked->web_intent_state === 'ready' && $locked->web_intent_id) {
                return ['action' => 'reuse', 'purchase' => $locked];
            }
            abort_if($locked->web_intent_state !== null, 409, 'محاولة الدفع غير مؤكدة؛ تحقق من الحالة أولاً.');
            $locked->update(['web_intent_state' => 'creating']);
            return ['action' => 'create', 'purchase' => $locked];
        }, 3);
        $purchase = $state['purchase'];
        if ($state['action'] === 'reuse') {
            $existing = Http::acceptJson()->withBasicAuth($secret, '')->timeout(20)
                ->get('https://api.moyasar.com/v1/payment_intents/'.$purchase->web_intent_id);
            abort_unless($existing->successful() && ($existing->json('status') === 'created')
                && (int) $existing->json('amount') === $purchase->amount_halalas
                && $existing->json('currency') === 'SAR'
                && $existing->json('callback_url') === $callback
                && \Carbon\Carbon::parse($existing->json('expires_at'))->isFuture(), 409);
            return ['intent_id' => $purchase->web_intent_id,
                'client_secret' => Crypt::decryptString($purchase->web_client_secret_enc),
                'given_id' => $purchase->given_id];
        }

        try {
            $response = Http::acceptJson()->withBasicAuth($secret, '')->timeout(20)
                ->post('https://api.moyasar.com/v1/payment_intents', [
                    'amount' => $purchase->amount_halalas, 'currency' => 'SAR',
                    'description' => 'ZAD Sync family subscription #'.$purchase->id,
                    'callback_url' => $callback,
                ]);
        } catch (ConnectionException) {
            abort(503, 'نتيجة إنشاء جلسة الدفع غير مؤكدة. راجع حالة الدفع قبل المحاولة مجددًا.');
        }
        $intent = $response->json();
        abort_unless($response->created() && is_array($intent)
            && Str::isUuid((string) ($intent['id'] ?? ''))
            && str_starts_with((string) ($intent['client_secret'] ?? ''), 'pi_secret_')
            && ($intent['status'] ?? '') === 'created'
            && (int) ($intent['amount'] ?? 0) === $purchase->amount_halalas
            && ($intent['currency'] ?? '') === 'SAR'
            && ($intent['callback_url'] ?? '') === $callback
            && isset($intent['expires_at'])
            && \Carbon\Carbon::parse($intent['expires_at'])->between(now(), now()->addHours(2)), 503,
            'لم تتأكد جلسة الدفع؛ تحقق من حالتها قبل إعادة المحاولة.');
        DB::transaction(function () use ($purchase, $intent): void {
            $locked = FamilyStorePurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->web_intent_state === 'creating' && $locked->status === 'pending', 409);
            $locked->update(['web_intent_state' => 'ready', 'web_intent_id' => $intent['id'],
                'web_client_secret_enc' => Crypt::encryptString($intent['client_secret']),
                'expires_at' => \Carbon\Carbon::parse($intent['expires_at'])]);
        });
        return ['intent_id' => $intent['id'], 'client_secret' => $intent['client_secret'],
            'given_id' => $purchase->given_id];
    }

    /** Only a direct server-to-Moyasar lookup may activate a plan. */
    public function reconcile(FamilyStorePurchase $purchase, ?string $paymentId = null): FamilyStorePurchase
    {
        if ($purchase->status === 'paid' && $purchase->receipt_snapshot) {
            $this->receipts->pdf($purchase);
            return $purchase->fresh();
        }
        if (! $purchase->given_id || ! in_array($purchase->status, ['pending', 'expired', 'unknown'], true)) {
            return $purchase->fresh();
        }
        if ($purchase->mode === 'coupon') {
            return $purchase->amount_halalas === 0 ? $this->activateFree($purchase) : $purchase->fresh();
        }
        $secret = $this->modes->credentials($purchase->mode)['secret_key'];
        $paymentId ??= $purchase->given_id;
        abort_unless(Str::isUuid($paymentId), 422);
        try {
            $response = Http::acceptJson()->withBasicAuth($secret, '')->timeout(20)
                ->get('https://api.moyasar.com/v1/payments/'.rawurlencode($paymentId));
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payment' => ['تعذر التأكد من الدفع؛ حاول التحقق لاحقاً.']]);
        }
        if ($response->notFound()) {
            $purchase->update(['last_checked_at' => now()]);
            if ($purchase->expires_at->isPast()) {
                // An unpaid, expired attempt must not keep a limited coupon use locked.
                $this->coupons->release($purchase);
            }
            return $purchase->fresh();
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw ValidationException::withMessages(['payment' => ['تعذر التحقق من عملية ميسر.']]);
        }
        $payment = $response->json();
        abort_unless(
            ($payment['id'] ?? null) === $paymentId
            && ($paymentId === $purchase->given_id || ($purchase->web_intent_id !== null
                && ($payment['payment_intent_id'] ?? null) === $purchase->web_intent_id))
            && (int) ($payment['amount'] ?? -1) === $purchase->amount_halalas
            && ($payment['currency'] ?? null) === $purchase->currency
            && (int) data_get($payment, 'metadata.family_store_purchase_id', 0) === $purchase->id
            && (int) data_get($payment, 'metadata.productive_family_id', 0) === $purchase->productive_family_id
            && (string) data_get($payment, 'metadata.environment', '') === $purchase->mode
            && (! array_key_exists('live', $payment) || $payment['live'] === ($purchase->mode === 'live')),
            422, 'بيانات الدفع لا تطابق اشتراك الأسرة.'
        );
        if (($payment['status'] ?? '') !== 'paid') {
            if (in_array($payment['status'] ?? '', ['failed', 'canceled', 'voided'], true)) {
                $purchase->update(['status' => 'failed']);
                $this->coupons->release($purchase);
            } else {
                $purchase->update(['last_checked_at' => now()]);
            }
            return $purchase->fresh();
        }

        DB::transaction(function () use ($purchase, $payment): void {
            ProductiveFamily::query()->whereKey($purchase->productive_family_id)->lockForUpdate()->firstOrFail();
            $locked = FamilyStorePurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->paid_at || $locked->grant_id) {
                return;
            }
            $grant = $this->storefront->activatePurchase($locked->productive_family_id, $locked->plan_code,
                $locked->duration_days, 'Moyasar payment '.$payment['id']);
            $locked->update([
                'status' => 'paid', 'paid_at' => now(),
                'provider_payment_id' => $payment['id'], 'grant_id' => $grant->id,
            ]);
            $this->coupons->redeem($locked);
            $this->receipts->issue($locked->fresh(), $payment['id']);
        }, 3);
        // PDF is archived before reporting success. A later retry can recover a failed render.
        $this->receipts->pdf($purchase->fresh());
        return $purchase->fresh();
    }

    private function halalas(string $price): int
    {
        abort_unless(preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/', $price, $m), 422);
        return ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
