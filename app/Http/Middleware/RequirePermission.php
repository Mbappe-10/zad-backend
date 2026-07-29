<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission($permission), 403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');

        return $next($request);
    }
}
