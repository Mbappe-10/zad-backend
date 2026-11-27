<?php

namespace App\Http\Middleware;

use App\Services\App\CashierStaffService;
use Closure;
use Illuminate\Http\Request;

/**
 * Employee cashier routes carry the membership id in the URL. Resolve who she is
 * once, then drop the id so the shared cashier actions receive their usual arguments.
 */
class ResolveCashierMembership
{
    public function handle(Request $request, Closure $next)
    {
        app(CashierStaffService::class)->resolve($request);
        $request->route()->forgetParameter('membership');

        return $next($request);
    }
}
