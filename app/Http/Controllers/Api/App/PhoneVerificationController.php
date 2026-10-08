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
                'regex:/^05[0-9]{8}$/',
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
                    'جلسة الجهاز مطلوبة لإتمام الطلب.',
                ],
            ]);
        }

        $phone = InternalTesting::normalizePhone($data['phone']);

        /*
         * Checkout currently uses Laravel internal OTP until
         * the real SMS provider is activated.
         *
         * Login/join remain protected by the existing test gate.
         */
        if (
            $purpose !== 'checkout'
            && ! InternalTesting::exposesOtpFor($phone)
        ) {
            throw ValidationException::withMessages([
                'phone' => [
                    'SMS verification is not enabled yet.',
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
            'guest_session_id' => $data['guest_session_id'] ?? null,
        ]);

        $payload = [
            'verification_id' => $verification->id,
            'expires_in_seconds' => $expiresMinutes * 60,
            'channel' => $purpose === 'checkout'
                ? 'internal_test'
                : 'sms',
            'test_mode' => $purpose === 'checkout',
            'message' => $purpose === 'checkout'
                ? 'تم إنشاء رمز التحقق التجريبي.'
                : 'تم إنشاء رمز التحقق.',
        ];

        if (
            $purpose === 'checkout'
            || InternalTesting::exposesOtpFor($phone)
        ) {
            $payload['development_code'] = $code;
        }

        return response()->json($payload, 201);
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
