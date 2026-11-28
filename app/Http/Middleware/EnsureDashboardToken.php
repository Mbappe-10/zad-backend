<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard APIs (finance, delivery, workforce, reports, admin…) answer only to a
 * dashboard sign-in. App tokens (Google sign-in for customers, families, drivers)
 * carry every ability, so the token itself is checked, not its abilities.
 */
class EnsureDashboardToken
{
    public const TOKEN_NAME = 'zad-admin-dashboard';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        if (method_exists($user, 'isPlatformOwner') && $user->isPlatformOwner()) {
            return $next($request);
        }
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->name === self::TOKEN_NAME, 403,
            'هذه الخدمة للوحة التحكم فقط.');

        return $next($request);
    }
}
