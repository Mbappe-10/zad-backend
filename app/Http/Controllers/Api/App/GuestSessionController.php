<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppGuestSession;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestSessionController extends Controller
{
    public function __construct(
        private readonly GuestSessionAuthenticator $authenticator,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:191'],
            'push_token' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'permissions' => ['nullable', 'array'],
        ]);

        $guest = AppGuestSession::query()->create([
            ...$data,
            'last_seen_at' => now(),
        ]);

        [$token, $expiresAt] = $this->authenticator->issue($guest);

        return response()->json([
            'guest_session_id' => $guest->id,
            'guest_session_token' => $token,
            'guest_session_token_expires_at' => $expiresAt->toISOString(),
            'mode' => 'guest',
            'next' => 'home',
        ], 201);
    }

    public function update(
        Request $request,
        AppGuestSession $guest,
    ): JsonResponse {
        $this->authenticator->authenticate(
            $guest,
            $this->authenticator->tokenFromRequest($request),
            $request->input('device_id'),
        );

        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:191'],
            'push_token' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'permissions' => ['nullable', 'array'],
        ]);

        unset($data['device_id']);

        $guest->update([
            ...$data,
            'last_seen_at' => now(),
        ]);

        // The Flutter client requests renewal when the guest token is
        // close to expiry. Return the new proof without changing the
        // guest-session ID so the customer's existing orders remain linked.
        if ($request->boolean('rotate_token')) {
            [$token, $expiresAt] = $this->authenticator->issue($guest);

            return response()->json(['data' => [
                'guest_session_id' => $guest->id,
                'guest_session_token' => $token,
                'guest_session_token_expires_at' => $expiresAt->toISOString(),
            ]]);
        }

        return response()->json(['data' => $guest->fresh()]);
    }

    public function destroy(
        Request $request,
        AppGuestSession $guest,
    ): JsonResponse {
        $this->authenticator->authenticate(
            $guest,
            $this->authenticator->tokenFromRequest($request),
            $request->input('device_id'),
        );
        $this->authenticator->revoke($guest);

        return response()->json(['revoked' => true]);
    }
}
