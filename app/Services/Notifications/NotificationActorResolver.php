<?php

namespace App\Services\Notifications;

use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationActorResolver
{
    public function __construct(private GuestSessionAuthenticator $guests)
    {
    }

    public function resolve(Request $request): NotificationActor
    {
        $user = $request->user() ?: Auth::guard('sanctum')->user();
        if ($user) {
            return new NotificationActor(user: $user);
        }

        abort_if($request->bearerToken() !== null, 401, 'The authentication token is invalid.');

        $guestId = trim((string) $request->header('X-Guest-Session', ''));
        abort_if($guestId === '', 401, 'A guest session identifier is required.');
        $token = $this->guests->tokenFromRequest($request);
        $deviceId = $request->input('device_id', $request->header('X-Device-Id'));
        $guest = $this->guests->authenticate($guestId, $token, is_string($deviceId) ? $deviceId : null);

        return new NotificationActor(guest: $guest);
    }
}
