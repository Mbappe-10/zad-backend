<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\ZatcaDocument;
use App\Services\App\CashierStaffService;
use App\Services\App\CashierZadService;
use App\Services\App\ZatcaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Optional: link the store with ZATCA's Fatoora platform (e-invoicing phase 2). Owner only. */
class ZatcaController extends Controller
{
    public function __construct(
        private readonly CashierStaffService $staff,
        private readonly ZatcaService $zatca,
        private readonly CashierZadService $cashier,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $store = $this->owner($request, false);

        return response()->json(['data' => $this->zatca->payload($store) + ['entitled' => $this->entitled($store)]]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $store = $this->owner($request);
        $data = $request->validate($this->zatca->profileRules(), [
            'building_number.digits' => 'رقم المبنى ٤ أرقام (من العنوان الوطني).',
            'postal_code.digits' => 'الرمز البريدي ٥ أرقام.',
            'id_number.regex' => 'رقم السجل أو الوثيقة أرقام وحروف إنجليزية فقط.',
        ]);
        $this->zatca->saveProfile($store, $data);

        return response()->json(['message' => 'تم حفظ بيانات المنشأة.', 'data' => $this->zatca->payload($store) + ['entitled' => true]]);
    }

    public function onboard(Request $request): JsonResponse
    {
        $store = $this->owner($request);
        $otp = $request->validate(['otp' => ['required', 'digits:6']], ['otp.digits' => 'رمز التحقق ٦ أرقام من بوابة فاتورة.'])['otp'];
        $this->zatca->onboard($store, $otp);

        return response()->json(['message' => 'تم ربط متجرك بمنصة فاتورة. فواتيرك الجاية تنرسل للهيئة تلقائياً.',
            'data' => $this->zatca->payload($store->refresh()) + ['entitled' => true]]);
    }

    /** Sends again whatever is still pending for this store now (instead of waiting for the scheduler). */
    public function retry(Request $request): JsonResponse
    {
        $store = $this->owner($request);
        $docs = ZatcaDocument::query()->where('store_id', $store->id)->where('status', 'pending')->orderBy('icv')->limit(50)->get();
        foreach ($docs as $doc) {
            $doc->forceFill(['next_attempt_at' => null])->save();
            $this->zatca->submit($doc);
        }

        return response()->json(['data' => $this->zatca->payload($store) + ['entitled' => true]]);
    }

    private function owner(Request $request, bool $requirePro = true): \App\Models\Store
    {
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner(), 403, 'الربط مع منصة فاتورة لصاحب المتجر فقط.');
        abort_unless($this->zatca->ready(), 503, 'الخدمة غير جاهزة حالياً.');
        if ($requirePro) {
            abort_unless($this->entitled($ctx->store), 403, 'الربط مع منصة فاتورة من مزايا كاشير زاد برو.');
        }

        return $ctx->store;
    }

    private function entitled(\App\Models\Store $store): bool
    {
        return (bool) ($this->cashier->entitlement($store->productiveFamily)['features']['zatca'] ?? false);
    }
}
