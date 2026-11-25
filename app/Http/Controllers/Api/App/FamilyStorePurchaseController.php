<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\FamilyStorePurchase;
use App\Models\ProductiveFamily;
use App\Services\App\FamilyStorePurchaseService;
use App\Services\App\FamilyStorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyStorePurchaseController extends Controller
{
    public function __construct(
        private readonly FamilyStorePurchaseService $purchases,
        private readonly FamilyStorefrontService $storefront,
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
        $purchase = $this->purchases->reconcile($purchase);

        return response()->json([
            'data' => $this->summary($purchase),
            'entitlement' => $this->storefront->entitlement($family),
        ]);
    }

    public function callback(string $token): JsonResponse
    {
        $purchase = FamilyStorePurchase::query()
            ->where('callback_token_hash', hash('sha256', $token))->firstOrFail();
        if (! $purchase->invoice_id) {
            return response()->json(['received' => true], 202);
        }
        // The callback body is untrusted. Only Moyasar's authenticated invoice API can grant a plan.
        $this->purchases->reconcile($purchase);

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
            'checkout_url' => $purchase->status === 'pending' ? $purchase->checkout_url : null,
            'expires_at' => $purchase->expires_at?->toISOString(),
            'paid_at' => $purchase->paid_at?->toISOString(),
            'starts_at' => $purchase->grant_id
                ? \App\Models\FamilyStoreGrant::query()->find($purchase->grant_id)?->starts_at?->toISOString()
                : null,
        ];
    }
}
