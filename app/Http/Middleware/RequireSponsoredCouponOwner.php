<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSponsoredCouponOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null
                && $user instanceof \App\Models\User
                && $user->exists
                && $user->is_platform_owner === true,
            403,
            'هذه العملية مخصصة لمالك المنصة فقط.'
        );

        return $next($request);
    }
}