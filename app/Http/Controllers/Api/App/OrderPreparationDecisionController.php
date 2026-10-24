<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\OrderPreparationDecision;
use App\Models\PhoneVerification;
use App\Models\PlatformControl;
use App\Models\Store;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderPreparationDecisionController extends Controller {
    private function enabled(): void { abort_unless(config('zad_v4_family_actions.enabled', false), 404); }
    private function settings(): array {
        $saved = PlatformControl::query()->where('section','orders_sales')->value('value');
        $data = is_array($saved) ? $saved : (is_string($saved) ? json_decode($saved,true) : []);
        $minutes = $this->numbers($data['extensionMinutesCsv'] ?? '5,10,12,15', 5, 60, [5,10,12,15]);
        $sar = $this->numbers($data['compensationSarCsv'] ?? '3,4,5', 3, 100, [3,4,5]);
        return ['minutes'=>$minutes, 'sar'=>$sar, 'ttl'=>10];
    }
    private function numbers(mixed $csv, int $min, int $max, array $fallback): array {
        if (!is_string($csv) || !preg_match('/^[0-9, ]{1,100}$/', $csv)) return $fallback;
        $values = array_map('intval', explode(',', $csv));
        if (!$values || count($values)>20 || count($values)!==count(array_unique($values))) return $fallback;
        foreach ($values as $v) if ($v<$min || $v>$max) return $fallback;
        return $values;
    }
    private function ownsFamily(Request $request, Order $order): int {
        abort_unless($request->user()!==null, 401);
        $familyId=AppProfile::query()->where('user_id',$request->user()->id)->value('productive_family_id');
        abort_unless($familyId !== null && Store::query()->whereKey($order->store_id)->where('productive_family_id',$familyId)->exists(),403);
        return (int) $familyId;
    }
    private function ownsCustomer(Request $request, Order $order, GuestSessionAuthenticator $auth): void {
        $customerId = $request->user()?->appProfile?->customer_id;
        if ($customerId !== null && (int)$order->customer_id === (int)$customerId) return;
        // An order ID and public guest UUID never prove ownership by themselves.
        $token=$auth->tokenFromRequest($request);
        abort_unless(filled($order->guest_session_id), 403);
        $auth->authenticate((string)$order->guest_session_id, $token, (string)$request->header('X-Device-ID', ''));
    }
    private function verifiedContact(Order $order): bool {
        if (Schema::hasColumn('orders','contact_verification_state')) {
            return $order->contact_verification_state === 'verified' || ($order->contact_verification_state === null && $order->contact_phone !== null && PhoneVerification::query()->where('guest_session_id',$order->guest_session_id)->where('phone',$order->contact_phone)->whereNotNull('verified_at')->exists());
        }
        // Existing legacy flow creates orders only after a verified checkout phone.
        return $order->contact_phone !== null && PhoneVerification::query()
            ->where('guest_session_id',$order->guest_session_id)
            ->where('phone',$order->contact_phone)->whereNotNull('verified_at')->exists();
    }
    private function requireEligibleOrder(Order $order): void {
        abort_unless($order->payment_status === Order::PAYMENT_PAID, 409, 'لم يكتمل الدفع.');
        abort_unless($this->verifiedContact($order),409,'لم يكتمل توثيق جوال العميل.');
        abort_unless(in_array($order->status,['pending','accepted','preparing'],true) && $order->driver_id === null,409,'حالة الطلب لا تسمح بالتمديد أو الاعتذار.');
    }
    public function options(Request $request): JsonResponse {
        $this->enabled();
        abort_unless($request->user() !== null,401);
        return response()->json(['data'=>$this->settings()]);
    }
    public function familyRequest(Request $request, Order $order): JsonResponse {
        $this->enabled();
        $familyId=$this->ownsFamily($request,$order);
        $data=$request->validate([
            'kind'=>['required','in:extension,unable_to_fulfill'],
            'extra_minutes'=>['required_if:kind,extension','nullable','integer','min:5','max:60'],
            'compensation_sar'=>['required_if:kind,extension','nullable','integer','min:3','max:100'],
            'reason'=>['required','string','min:5','max:400'],
        ]);
        $settings=$this->settings();
        if ($data['kind']==='extension') {
            abort_unless(in_array((int)$data['extra_minutes'],$settings['minutes'],true),422,'مدة التمديد غير مسموحة من الإدارة.');
            abort_unless(in_array((int)$data['compensation_sar'],$settings['sar'],true),422,'مبلغ التعويض غير مسموح من الإدارة.');
        }
        $decision=DB::transaction(function () use ($order,$data,$familyId,$request,$settings): OrderPreparationDecision {
            $locked=Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->requireEligibleOrder($locked);
            if ($data['kind']==='extension') abort_unless(((int)$data['compensation_sar']*100) <= (int)round((float)$locked->total*100),422,'التعويض أكبر من المبلغ المدفوع.');
            abort_if(OrderPreparationDecision::query()->where('order_id',$locked->id)->exists(),409,'سبق تسجيل طلب تمديد أو تعذر تنفيذ لهذا الطلب.');
            return OrderPreparationDecision::query()->create([
                'order_id'=>$locked->id,'family_id'=>$familyId,'kind'=>$data['kind'],
                'status'=>$data['kind']==='extension'?'pending':'refund_review',
                'extra_minutes'=>$data['kind']==='extension'?(int)$data['extra_minutes']:null,
                'compensation_halalas'=>$data['kind']==='extension'?(int)$data['compensation_sar']*100:0,
                'compensation_state'=>$data['kind']==='extension'?'manual_review':'none',
                'reason'=>$data['reason'],
                'response_due_at'=>$data['kind']==='extension'?now()->addMinutes($settings['ttl']):null,
                'requested_by'=>$request->user()?->id,
            ]);
        },3);
        return response()->json(['message'=>'تم تسجيل الطلب، ولا يوجد استرداد مالي منفذ بعد.','data'=>$this->payload($decision)],201);
    }
    public function customerShow(Request $request, Order $order, GuestSessionAuthenticator $auth): JsonResponse {
        $this->enabled();$this->ownsCustomer($request,$order,$auth);
        $decision=OrderPreparationDecision::query()->where('order_id',$order->id)->first();
        return response()->json(['data'=>$decision?$this->payload($decision):null]);
    }
    public function respond(Request $request, Order $order, GuestSessionAuthenticator $auth): JsonResponse {
        $this->enabled();$this->ownsCustomer($request,$order,$auth);
        $data=$request->validate(['response'=>['required','in:accept,decline']]);
        $decision=DB::transaction(function () use ($order,$data): OrderPreparationDecision {
            $locked=Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->payment_status===Order::PAYMENT_PAID,409);
            $decision=OrderPreparationDecision::query()->where('order_id',$locked->id)->lockForUpdate()->firstOrFail();
            abort_unless($decision->kind==='extension' && $decision->status==='pending',409,'انتهت أو أغلقت مهلة القرار.');
            if (now()->greaterThanOrEqualTo($decision->response_due_at)) {
                $decision->update(['status'=>'expired']);
                abort(409,'انتهت مهلة الموافقة؛ يلزم مراجعة الدعم.');
            }
            if ($data['response']==='decline') {
                $decision->update(['status'=>'refund_review','responded_at'=>now()]);
            } else {
                $decision->update(['status'=>'accepted','responded_at'=>now()]);
                $locked->forceFill(['preparation_extended_until'=>now()->addMinutes($decision->extra_minutes)])->save();
            }
            return $decision->fresh();
        },3);
        return response()->json(['message'=>'تم تسجيل قرارك. أي تعويض مالي لا يُعتبر مستردًا حتى تؤكد بوابة الدفع ذلك.','data'=>$this->payload($decision)]);
    }
    private function payload(OrderPreparationDecision $d): array {
        $expired=$d->kind==='extension' && $d->status==='pending' && now()->greaterThanOrEqualTo($d->response_due_at);
        return ['id'=>$d->id,'kind'=>$d->kind,'status'=>$expired?'expired':$d->status,
            'extra_minutes'=>$d->extra_minutes,'compensation_sar'=>$d->compensation_halalas/100,
            'compensation_state'=>$d->compensation_state,'reason'=>$d->reason,
            'response_due_at'=>$d->response_due_at?->toIso8601String(),
            'no_refund_executed'=>true];
    }
}
