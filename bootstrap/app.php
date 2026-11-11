<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        |--------------------------------------------------------------------------
        | Render Trusted Proxy
        |--------------------------------------------------------------------------
        |
        | Render terminates HTTPS and forwards the real visitor address through
        | the standard X-Forwarded headers. Trusting the platform proxy prevents
        | every customer from sharing the same rate-limit identity.
        |
        */

        $middleware->trustProxies(
            at: '*',
            headers:
                Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO,
        );

        /*
        |--------------------------------------------------------------------------
        | Sanctum API Middleware
        |--------------------------------------------------------------------------
        |
        | نحافظ على دعم Sanctum، مع استثناء جميع مسارات API من حماية CSRF
        | لأن تسجيل الدخول الحالي يعتمد على Bearer Token وليس Session Cookie.
        |
        */

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : '/login'
        );

        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Global Security Headers
        |--------------------------------------------------------------------------
        */

        $middleware->append(
            SecurityHeaders::class
        );

        /*
        |--------------------------------------------------------------------------
        | Middleware Aliases
        |--------------------------------------------------------------------------
        */

        $middleware->alias([
            'sponsored.owner' => \App\Http\Middleware\RequireSponsoredCouponOwner::class,
            'permission' => RequirePermission::class,
            'security.headers' => SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $exception,
            Request $request
        ) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });
        /*
        |--------------------------------------------------------------------------
        | API JSON Exceptions
        |--------------------------------------------------------------------------
        |
        | جميع أخطاء مسارات API يتم إرجاعها بصيغة JSON.
        |
        */

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*'),
        );
    })
    ->create();
