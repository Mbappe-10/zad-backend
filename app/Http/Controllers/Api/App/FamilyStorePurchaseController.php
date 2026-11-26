<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\FamilyStorePurchase;
use App\Models\ProductiveFamily;
use App\Services\App\FamilyStorePurchaseService;
use App\Services\App\FamilyStorefrontService;
use App\Services\App\FamilySubscriptionReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyStorePurchaseController extends Controller
{
    public function __construct(
        private readonly FamilyStorePurchaseService $purchases,
        private readonly FamilyStorefrontService $storefront,
        private readonly FamilySubscriptionReceiptService $receipts,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $family = $this->family($request);
        $data = $request->validate(['plan' => ['required', 'in:silver,gold']]);
        $purchase = $this->purchases->start($family, $request->user()->id, $data['plan']);

        return response()->json(['data' => $this->summary($purchase)], $purchase->wasRecentlyCreated ? 201 : 200);
    }

    public function latest(Request $request): JsonResponse
    {
        $family = $this->family($request);
        $purchase = FamilyStorePurchase::query()->where('productive_family_id', $family->id)->latest('id')->first();

        return response()->json([
            'data' => $purchase ? $this->summary($purchase) : null,
            'entitlement' => $this->storefront->entitlement($family),
        ]);
    }

    public function verify(Request $request, FamilyStorePurchase $purchase): JsonResponse
    {
        $family = $this->family($request);
        abort_unless($purchase->productive_family_id === $family->id, 404);
        $data = $request->validate(['payment_id' => ['sometimes', 'uuid']]);
        $purchase = $this->purchases->reconcile($purchase, $data['payment_id'] ?? null);

        return response()->json([
            'data' => $this->summary($purchase),
            'entitlement' => $this->storefront->entitlement($family),
        ]);
    }

    public function webIntent(Request $request, FamilyStorePurchase $purchase): JsonResponse
    {
        $family = $this->family($request);
        abort_unless($purchase->productive_family_id === $family->id, 404);
        $request->validate(['given_id' => ['required', 'uuid', 'in:'.$purchase->given_id]]);
        return response()->json(['data' => $this->purchases->webIntent($purchase)]);
    }

    public function receipt(Request $request, FamilyStorePurchase $purchase): JsonResponse
    {
        $family = $this->family($request);
        abort_unless($purchase->productive_family_id === $family->id && $purchase->status === 'paid', 404);
        $bytes = $this->receipts->pdf($purchase);
        return response()->json(['number' => $purchase->receipt_number,
            'sha256' => hash('sha256', $bytes),
            'pdf_base64' => base64_encode($bytes)]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = trim((string) config('moyasar.webhook_secret', ''));
        $provided = trim((string) $request->input('secret_token', ''));
        abort_if($secret === '' || $provided === '' || ! hash_equals($secret, $provided), 401);
        if ($request->input('type') !== 'payment_paid') {
            return response()->json(['received' => true]);
        }
        $live = $request->input('live');
        abort_unless(is_bool($live), 422);
        $paymentId = (string) data_get($request->all(), 'data.id', '');
        $candidate = (int) data_get($request->all(), 'data.metadata.family_store_purchase_id', 0);
        $purchase = $candidate > 0 ? FamilyStorePurchase::query()->find($candidate) : null;
        if (! $purchase) {
            $purchase = FamilyStorePurchase::query()->where('given_id', $paymentId)->first();
        }
        if ($purchase && $purchase->mode !== ($live ? 'live' : 'sandbox')) {
            return response()->json(['received' => true]);
        }
        if ($purchase) {
            $this->purchases->reconcile($purchase, $paymentId);
        }
        return response()->json(['received' => true]);
    }

    private function family(Request $request): ProductiveFamily
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->first();
        abort_unless($profile && in_array('productive_family', $profile->roles ?? [], true)
            && $profile->productive_family_id, 403, 'حساب أسرة منتجة مطلوب.');

        return ProductiveFamily::query()->findOrFail($profile->productive_family_id);
    }

    private function summary(FamilyStorePurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'plan' => $purchase->plan_code,
            'amount_halalas' => $purchase->amount_halalas,
            'currency' => $purchase->currency,
            'status' => $purchase->status,
            'payment_config' => $purchase->status === 'pending' ? $this->purchases->clientConfig($purchase) : null,
            'given_id' => $purchase->given_id,
            'expires_at' => $purchase->expires_at?->toISOString(),
            'paid_at' => $purchase->paid_at?->toISOString(),
            'receipt_number' => $purchase->receipt_number,
            'receipt_hash' => $purchase->receipt_hash,
            'starts_at' => $purchase->grant_id
                ? \App\Models\FamilyStoreGrant::query()->find($purchase->grant_id)?->starts_at?->toISOString()
                : null,
        ];
    }
}
