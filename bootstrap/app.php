<?php

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
        | Sanctum API Middleware
        |--------------------------------------------------------------------------
        |
        | نحافظ على دعم Sanctum، مع استثناء جميع مسارات API من حماية CSRF
        | لأن تسجيل الدخول الحالي يعتمد على Bearer Token وليس Session Cookie.
        |
        */

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
            \App\Http\Middleware\SecurityHeaders::class
        );

        /*
        |--------------------------------------------------------------------------
        | Middleware Aliases
        |--------------------------------------------------------------------------
        */

        $middleware->alias([
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'security.headers' => \App\Http\Middleware\SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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