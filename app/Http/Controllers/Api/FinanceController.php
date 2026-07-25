<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{CommissionRule, FinancialLedgerEntry, Payment, PaymentProvider, Payout, Refund, Wallet, WalletTransaction};
use App\Services\FinancialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function __construct(private readonly FinancialService $service) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data'=>[
            'wallets'=>Wallet::count(),
            'available_balance'=>(float)Wallet::sum('available_balance'),
            'pending_balance'=>(float)Wallet::sum('pending_balance'),
            'payments_total'=>(float)Payment::where('status','paid')->sum('gross_amount'),
            'provider_fees'=>(float)Payment::where('status','paid')->sum('provider_fee'),
            'payouts_pending'=>(float)Payout::where('status','pending')->sum('amount'),
            'refunds_total'=>(float)Refund::where('status','completed')->sum('amount'),
        ]]);
    }

    public function providers(Request $request): JsonResponse
    {
        $query=PaymentProvider::query();
        if ($request->filled('search')) $query->where(fn($q)=>$q->where('name','like','%'.$request->search.'%')->orWhere('code','like','%'.$request->search.'%'));
        return response()->json($query->latest()->paginate(min((int)$request->input('per_page',25),100)));
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $data=$request->validate(['code'=>'required|string|max:50|unique:payment_providers,code','name'=>'required|string|max:150','fixed_fee'=>'nullable|numeric|min:0','percentage_fee'=>'nullable|numeric|min:0|max:100','currency'=>'nullable|string|size:3','is_active'=>'nullable|boolean','settings'=>'nullable|array']);
        return response()->json(['message'=>'تمت إضافة مزود الدفع.','data'=>PaymentProvider::create($data)],201);
    }

    public function updateProvider(Request $request, PaymentProvider $provider): JsonResponse
    {
        $data=$request->validate(['code'=>'sometimes|string|max:50|unique:payment_providers,code,'.$provider->id,'name'=>'sometimes|string|max:150','fixed_fee'=>'nullable|numeric|min:0','percentage_fee'=>'nullable|numeric|min:0|max:100','currency'=>'nullable|string|size:3','is_active'=>'nullable|boolean','settings'=>'nullable|array']);
        $provider->update($data); return response()->json(['message'=>'تم تحديث مزود الدفع.','data'=>$provider->fresh()]);
    }

    public function payments(Request $request): JsonResponse
    {
        $query=Payment::query()->with(['order:id,number','provider:id,name']);
        if($request->filled('status'))$query->where('status',$request->status);
        if($request->filled('search'))$query->where(fn($q)=>$q->where('reference','like','%'.$request->search.'%')->orWhere('provider_reference','like','%'.$request->search.'%'));
        return response()->json($query->latest()->paginate(min((int)$request->input('per_page',25),100)));
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data=$request->validate(['order_id'=>'nullable|exists:orders,id','provider_id'=>'nullable|exists:payment_providers,id','reference'=>'required|string|max:100|unique:payments,reference','provider_reference'=>'nullable|string|max:150','method'=>'nullable|string|max:80','currency'=>'nullable|string|size:3','gross_amount'=>'required|numeric|min:0.01','status'=>'required|in:pending,paid,failed,cancelled','metadata'=>'nullable|array']);
        $provider=isset($data['provider_id'])?PaymentProvider::find($data['provider_id']):null;
        $fee=round((float)($provider?->fixed_fee??0)+((float)$data['gross_amount']*(float)($provider?->percentage_fee??0)/100),2);
        $data['provider_fee']=$fee; $data['net_amount']=round((float)$data['gross_amount']-$fee,2);
        if($data['status']==='paid')$data['paid_at']=now(); if($data['status']==='failed')$data['failed_at']=now();
        return response()->json(['message'=>'تم تسجيل الدفعة.','data'=>Payment::create($data)],201);
    }

    public function commissionRules(Request $request): JsonResponse
    {
        $query=CommissionRule::query(); if($request->filled('search'))$query->where('name','like','%'.$request->search.'%');
        return response()->json($query->orderBy('priority')->paginate(min((int)$request->input('per_page',25),100)));
    }

    public function storeCommissionRule(Request $request): JsonResponse
    {
        $data=$request->validate(['name'=>'required|string|max:150','beneficiary_type'=>'required|in:platform,store,driver','calculation_type'=>'required|in:percentage,fixed','value'=>'required|numeric|min:0','minimum_amount'=>'nullable|numeric|min:0','maximum_amount'=>'nullable|numeric|min:0','city_id'=>'nullable|exists:cities,id','store_id'=>'nullable|exists:stores,id','vehicle_type'=>'nullable|string|max:50','priority'=>'nullable|integer|min:1','is_active'=>'nullable|boolean','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after_or_equal:starts_at']);
        return response()->json(['message'=>'تم إنشاء قاعدة العمولة.','data'=>CommissionRule::create($data)],201);
    }

    public function updateCommissionRule(Request $request, CommissionRule $rule): JsonResponse
    {
        $data=$request->validate(['name'=>'sometimes|string|max:150','beneficiary_type'=>'sometimes|in:platform,store,driver','calculation_type'=>'sometimes|in:percentage,fixed','value'=>'sometimes|numeric|min:0','minimum_amount'=>'nullable|numeric|min:0','maximum_amount'=>'nullable|numeric|min:0','city_id'=>'nullable|exists:cities,id','store_id'=>'nullable|exists:stores,id','vehicle_type'=>'nullable|string|max:50','priority'=>'nullable|integer|min:1','is_active'=>'nullable|boolean','starts_at'=>'nullable|date','ends_at'=>'nullable|date']);
        $rule->update($data); return response()->json(['message'=>'تم تحديث قاعدة العمولة.','data'=>$rule->fresh()]);
    }

    public function wallets(Request $request): JsonResponse
    {
        $query=Wallet::query(); if($request->filled('is_frozen'))$query->where('is_frozen',$request->boolean('is_frozen'));
        return response()->json($query->latest()->paginate(min((int)$request->input('per_page',25),100)));
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $query=WalletTransaction::query(); if($request->filled('wallet_id'))$query->where('wallet_id',$request->wallet_id); if($request->filled('type'))$query->where('type',$request->type);
        return response()->json($query->latest()->paginate(min((int)$request->input('per_page',25),100)));
    }

    public function creditWallet(Request $request, Wallet $wallet): JsonResponse
    {
        $data=$request->validate(['amount'=>'required|numeric|min:0.01','type'=>'required|string|max:40','description'=>'required|string|max:500']);
        return response()->json(['message'=>'تمت إضافة الرصيد.','data'=>$this->service->credit($wallet,(float)$data['amount'],$data['type'],$data['description'],null,$request->user()?->id)],201);
    }

    public function freezeWallet(Wallet $wallet): JsonResponse { $wallet->update(['is_frozen'=>!$wallet->is_frozen]); return response()->json(['message'=>'تم تحديث حالة المحفظة.','data'=>$wallet->fresh()]); }

    public function payouts(Request $request): JsonResponse
    { $q=Payout::query()->with('wallet'); if($request->filled('status'))$q->where('status',$request->status); return response()->json($q->latest()->paginate(min((int)$request->input('per_page',25),100))); }

    public function requestPayout(Request $request, Wallet $wallet): JsonResponse
    { $data=$request->validate(['amount'=>'required|numeric|min:1','fee'=>'nullable|numeric|min:0','bank_name'=>'nullable|string|max:150','iban'=>'nullable|string|max:50','account_name'=>'nullable|string|max:150','notes'=>'nullable|string']); return response()->json(['message'=>'تم إنشاء طلب الصرف.','data'=>$this->service->requestPayout($wallet,$data,$request->user()?->id)],201); }

    public function decidePayout(Request $request, Payout $payout): JsonResponse
    { $data=$request->validate(['decision'=>'required|in:approve,reject']); return response()->json(['message'=>'تم تنفيذ القرار.','data'=>$this->service->decidePayout($payout,$data['decision'],$request->user()?->id)]); }

    public function refund(Request $request, Payment $payment): JsonResponse
    { $data=$request->validate(['amount'=>'required|numeric|min:0.01','reason'=>'required|string|max:1000']); return response()->json(['message'=>'تم تنفيذ الاسترداد.','data'=>$this->service->refund($payment,$data,$request->user()?->id)],201); }

    public function ledger(Request $request): JsonResponse
    { $q=FinancialLedgerEntry::query(); if($request->filled('account_code'))$q->where('account_code',$request->account_code); return response()->json($q->latest('entry_date')->latest('id')->paginate(min((int)$request->input('per_page',50),200))); }
}
