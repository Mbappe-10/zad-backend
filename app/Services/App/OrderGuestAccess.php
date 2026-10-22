<?php

namespace App\Services\App;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Strict guest-session ownership boundary for ALL orders (legacy and V5).
 * Legacy APKs that only submit UUIDs must upgrade / reauthenticate.
 * Local review candidate; never deploy before client migration is proven.
 */
final class OrderGuestAccess
{
    public function __construct(private readonly GuestSessionAuthenticator $guests) {}

    public function allows(Request $request, Order $order): bool
    {
        $user = $request->user();
        if ($user !== null
            && $order->customer_id !== null
            && (int) $order->customer_id === (int) $user->appProfile?->customer_id) {
            return true;
        }

        $providedId = trim((string) $request->header('X-Guest-Session', ''));
        $storedId = (string) $order->guest_session_id;
        if ($providedId === '' || $storedId === '' || ! hash_equals($storedId, $providedId)) {
            return false;
        }

        // A guest session UUID is a locator, NEVER proof of order ownership.
        // Legacy and V5 orders both require an issued and valid bearer token.
        // This candidate deliberately breaks UUID-only old APK flows;
        // do not deploy before a compatible APK + recovery strategy exists.
        $token = trim((string) $request->header(GuestSessionAuthenticator::HEADER, ''));
        if ($token === '') {
            return false;
        }

        // Invalid or revoked token raises 401; never fall back to UUID.
        $this->guests->authenticate(
            $storedId,
            $token,
            (string) $request->header('X-Device-ID', ''),
        );

        return true;
    }

    public function assert(Request $request, Order $order): void
    {
        abort_unless($this->allows($request, $order), 403, 'لا يمكنك الوصول إلى هذا الطلب.');
    }

    public function assertNewSession(Request $request, string $guestSessionId): void
    {
        $providedId = trim((string) $request->header('X-Guest-Session', ''));
        abort_unless($providedId !== '' && hash_equals($guestSessionId, $providedId), 403);

        $this->guests->authenticate(
            $guestSessionId,
            $this->guests->tokenFromRequest($request),
            (string) $request->header('X-Device-ID', ''),
        );
    }
}
