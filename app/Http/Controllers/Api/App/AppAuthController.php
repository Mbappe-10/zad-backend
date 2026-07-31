<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AppAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'=>'required|string|max:100',
            'phone'=>'required|string|max:20|unique:users,phone|unique:customers,phone',
            'email'=>'nullable|email|max:150|unique:users,email|unique:customers,email',
            'password'=>'required|string|min:8|max:72|confirmed',
        ]);

        [$user,$profile] = DB::transaction(function () use ($data): array {
            $user = User::create([
                'name'=>$data['name'], 'name_ar'=>$data['name'], 'phone'=>$data['phone'],
                'email'=>$data['email'] ?? null, 'password'=>$data['password'],
                'status'=>'active', 'is_approved'=>true,
            ]);
            $customer = Customer::create([
                'name'=>$data['name'], 'phone'=>$data['phone'], 'email'=>$data['email'] ?? null, 'status'=>'active',
            ]);
            $profile = AppProfile::create([
                'user_id'=>$user->id, 'customer_id'=>$customer->id,
                'roles'=>['customer'], 'active_mode'=>'customer',
            ]);
            return [$user,$profile];
        });

        return response()->json([
            'message'=>'تم إنشاء الحساب بنجاح.',
            'token'=>$user->createToken('zad-mobile-app')->plainTextToken,
            'user'=>$this->payload($user,$profile),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data=$request->validate(['login'=>'required|string','password'=>'required|string','device_name'=>'nullable|string|max:100']);
        $user=User::query()->where('phone',$data['login'])->orWhere('email',$data['login'])->first();
        if (!$user || !Hash::check($data['password'],$user->password)) {
            throw ValidationException::withMessages(['login'=>['بيانات الدخول غير صحيحة.']]);
        }
        abort_unless($user->status === 'active', 403, 'الحساب غير نشط.');
        $profile=AppProfile::firstOrCreate(['user_id'=>$user->id],['roles'=>['customer'],'active_mode'=>'customer']);
        return response()->json([
            'token'=>$user->createToken($data['device_name'] ?? 'zad-mobile-app')->plainTextToken,
            'user'=>$this->payload($user,$profile),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $profile=AppProfile::firstOrCreate(['user_id'=>$request->user()->id],['roles'=>['customer'],'active_mode'=>'customer']);
        return response()->json(['user'=>$this->payload($request->user(),$profile)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message'=>'تم تسجيل الخروج.']);
    }

    private function payload(User $user, AppProfile $profile): array
    {
        return ['id'=>$user->id,'name'=>$user->displayName('ar'),'email'=>$user->email,'phone'=>$user->phone,
            'roles'=>$profile->roles ?? ['customer'],'active_mode'=>$profile->active_mode,
            'customer_id'=>$profile->customer_id,'productive_family_id'=>$profile->productive_family_id,'driver_id'=>$profile->driver_id];
    }
}
