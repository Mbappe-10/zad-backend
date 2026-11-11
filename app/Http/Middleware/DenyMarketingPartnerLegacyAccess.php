<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Block only marketing_partner from legacy admin/core/platform APIs. */
class DenyMarketingPartnerLegacyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasRole('marketing_partner')) {
            abort(403, 'Marketing partner access is restricted to scoped read-only endpoints.');
        }

        return $next($request);
    }
}