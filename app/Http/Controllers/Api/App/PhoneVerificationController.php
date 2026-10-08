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
                'regex:/^05[0-9]+$/',
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

        // ZAD_CHECKOUT_BACKEND_OTP_DEMO_V2
        // Checkout accepts a flexible numeric phone for temporary backend OTP.
        // Login and join keep the normal Saudi mobile format.
        if (
            $purpose !== 'checkout'
            && preg_match(
                '/^(?:\+966|00966|966|0)?5[0-9]{8}$/',
                trim((string) $data['phone']),
            ) !== 1
        ) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid Saudi mobile number.'],
            ]);
        }

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

        // ZAD_PRELAUNCH_ANY_05_OTP_V4
        $rawPhone = preg_replace(
            '/\D+/',
            '',
            trim((string) $data['phone']),
        ) ?? '';

        $testAny05 = InternalTesting::enabled()
            && (bool) config(
                'internal_testing.otp.expose_code',
                false,
            );

        /*
         * Prelaunch testing:
         * Any numeric value beginning with 05 is accepted.
         * Example: 050000000003
         */
        if (! preg_match('/^05[0-9]+$/', $rawPhone)) {
            throw ValidationException::withMessages([
                'phone' => [
                    'Phone number must start with 05 and contain digits only.',
                ],
            ]);
        }

        /*
         * When internal testing is disabled,
         * return to the normal Saudi mobile format.
         */
        if (
            ! $testAny05
            && ! preg_match('/^05[0-9]{8}$/', $rawPhone)
        ) {
            throw ValidationException::withMessages([
                'phone' => [
                    'Enter a valid Saudi mobile number.',
                ],
            ]);
        }

        $phone = $testAny05
            ? $rawPhone
            : InternalTesting::normalizePhone($rawPhone);

        $exposeCode = $testAny05
            || InternalTesting::exposesOtpFor($phone);

        if (! $exposeCode) {
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
