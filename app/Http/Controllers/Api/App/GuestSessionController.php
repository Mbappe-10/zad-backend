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
        private readonly GuestSessionAuthenticator $guestSessions,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'nullable|string|max:191',
            'push_token' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'permissions' => 'nullable|array',
        ]);

        $guest = AppGuestSession::create([...$data, 'last_seen_at' => now()]);
        [$token, $expiresAt] = $this->guestSessions->issue($guest);

        // Return the token only when issuing this new session. Clients must
        // store it securely and send it as X-Guest-Session-Token on OTP calls.
        return response()->json([
            'guest_session_id' => $guest->id,
            'guest_session_token' => $token,
            'guest_session_token_expires_at' => $expiresAt->toISOString(),
            'mode' => 'guest',
            'next' => 'home',
        ], 201);
    }

    public function update(Request $request, AppGuestSession $guest): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'sometimes|required|string|max:191',
            'rotate_token' => 'sometimes|boolean',
            'push_token' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'permissions' => 'nullable|array',
        ]);

        // Require the existing secret before rotation, and bind to the device.
        $this->guestSessions->authenticate(
            $guest,
            $this->guestSessions->tokenFromRequest($request),
            $data['device_id'] ?? null,
        );

        $rotateToken = (bool) ($data['rotate_token'] ?? false);
        unset($data['rotate_token']);
        $guest->update([...$data, 'last_seen_at' => now()]);

        if (! $rotateToken) {
            return response()->json(['data' => $guest->fresh()]);
        }

        [$token, $expiresAt] = $this->guestSessions->issue($guest);

        // Flutter reads the nested data object on PATCH. Never expose the hash.
        return response()->json(['data' => [
            ...$guest->fresh()->toArray(),
            'guest_session_id' => (string) $guest->getKey(),
            'guest_session_token' => $token,
            'guest_session_token_expires_at' => $expiresAt->toISOString(),
        ]]);
    }
}
