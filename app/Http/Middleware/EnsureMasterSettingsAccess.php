<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMasterSettingsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        $isOwner = (bool) ($user->is_owner ?? false);
        $hasPermission = method_exists($user, 'can')
            && $user->can('master_settings.access');

        abort_unless($isOwner || $hasPermission, 403, 'غير مصرح بالوصول إلى مركز الإعدادات الرئيسي.');

        return $next($request);
    }
}
