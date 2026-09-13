<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Support\InternalTesting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^(?:\+966|00966|966|0)?5[0-9]{8}$/',
            ],
            'purpose' => [
                'nullable',
                'in:checkout,login,join',
            ],
            'guest_session_id' => [
                'nullable',
                'string',
                'max:36',
                'exists:app_guest_sessions,id',
            ],
        ]);

        $purpose = $data['purpose'] ?? 'checkout';

        if (
            $purpose === 'checkout'
            && empty($data['guest_session_id'])
        ) {
            throw ValidationException::withMessages([
                'guest_session_id' => [
                    'جلسة الجهاز مطلوبة لإرسال رمز الطلب.',
                ],
            ]);
        }

        $phone = InternalTesting::normalizePhone($data['phone']);
        $exposeCode = InternalTesting::exposesOtpFor($phone);

        /*
         * لا يوجد مزود SMS فعلي حتى الآن. في الإنتاج العام لا ننشئ رمزًا
         * صامتًا لن يصل إلى العميل. نسخة الاختبار تسمح فقط بالأرقام الموجودة
         * في قائمة Render الخاصة.
         */
        if (! $exposeCode) {
            throw ValidationException::withMessages([
                'phone' => [
                    'هذا الرقم غير مضاف إلى أرقام الاختبار، وخدمة SMS الفعلية لم تُفعّل بعد.',
                ],
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $expiresMinutes = InternalTesting::otpExpiresMinutes();
        $expiresAt = now()->addMinutes($expiresMinutes);

        PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();

        $verification = PhoneVerification::query()->create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'guest_session_id' =>
                $data['guest_session_id'] ?? null,
        ]);

        return response()->json([
            'verification_id' => $verification->id,
            'expires_in_seconds' => $expiresMinutes * 60,
            'channel' => 'internal_test',
            'test_mode' => true,
            'development_code' => $code,
            'message' =>
                'تم إنشاء رمز اختبار مؤقت داخل Laravel.',
        ], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => [
                'required',
                'integer',
                'exists:phone_verifications,id',
            ],
            'code' => ['required', 'digits:6'],
            'guest_session_id' => [
                'nullable',
                'string',
                'max:36',
                'exists:app_guest_sessions,id',
            ],
        ]);

        $verification = PhoneVerification::query()
            ->findOrFail($data['verification_id']);

        if (
            $verification->guest_session_id !== null
            && (
                empty($data['guest_session_id'])
                || ! hash_equals(
                    (string) $verification->guest_session_id,
                    (string) $data['guest_session_id'],
                )
            )
        ) {
            throw ValidationException::withMessages([
                'code' => [
                    'رمز التحقق لا يخص جلسة هذا الجهاز.',
                ],
            ]);
        }

        if (
            now()->greaterThan($verification->expires_at)
            || $verification->attempts >= 5
        ) {
            throw ValidationException::withMessages([
                'code' => [
                    'انتهت صلاحية رمز التحقق. اطلب رمزًا جديدًا.',
                ],
            ]);
        }

        if (! Hash::check($data['code'], $verification->code_hash)) {
            $verification->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['رمز التحقق غير صحيح.'],
            ]);
        }

        if ($verification->verified_at === null) {
            $verification->forceFill([
                'verified_at' => now(),
            ])->save();
        }

        return response()->json([
            'verified' => true,
            'verification_token' => encrypt([
                'id' => $verification->id,
                'phone' => $verification->phone,
                'purpose' => $verification->purpose,
                'guest_session_id' =>
                    $verification->guest_session_id,
                'expires' => now()->addMinutes(20)->timestamp,
            ]),
            'phone' => $verification->phone,
        ]);
    }
}
