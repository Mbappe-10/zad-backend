<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): array {
            $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return [
                Limit::perMinute((int) env('API_RATE_LIMIT_PER_MINUTE', 120))
                    ->by((string) $identity),
            ];
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email')));
            $key = ($email !== '' ? $email.'|' : '').$request->ip();

            return Limit::perMinute((int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5))
                ->by($key)
                ->response(fn () => response()->json([
                    'message' => 'تم تجاوز عدد محاولات تسجيل الدخول المسموح بها. حاول مرة أخرى بعد دقيقة.',
                ], 429));
        });
    }
}
