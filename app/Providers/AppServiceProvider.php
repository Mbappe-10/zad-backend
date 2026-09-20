<?php

namespace App\Providers;

use App\Filesystems\CloudinaryStorageAdapter;
use Cloudinary\Cloudinary;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Storage::extend('cloudinary', function ($app, array $config): LaravelFilesystemAdapter {
            $cloudinaryUrl = trim((string) ($config['cloudinary_url'] ?? ''));

            if ($cloudinaryUrl === '') {
                throw new RuntimeException('CLOUDINARY_URL is required for the Cloudinary filesystem.');
            }

            $cloudName = parse_url($cloudinaryUrl, PHP_URL_HOST);

            if (! is_string($cloudName) || $cloudName === '') {
                throw new RuntimeException('CLOUDINARY_URL does not contain a valid cloud name.');
            }

            $adapter = new CloudinaryStorageAdapter(
                new Cloudinary($cloudinaryUrl),
                $cloudName,
                (string) ($config['cloudinary_prefix'] ?? 'zad-sync'),
                (bool) ($config['cloudinary_secure'] ?? true),
            );

            return new LaravelFilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        // ZAD_SQLITE_LOCAL_PRAGMAS
        if (
            app()->environment('local') &&
            config('database.default') === 'sqlite'
        ) {
            $pdo = DB::connection('sqlite')->getPdo();

            $pdo->exec('PRAGMA journal_mode=MEMORY');
            $pdo->exec('PRAGMA temp_store=MEMORY');
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('PRAGMA busy_timeout=15000');
            $pdo->exec('PRAGMA foreign_keys=ON');
        }

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

        RateLimiter::for('payment-action', function (Request $request): Limit {
            $order = $request->route('order');
            $orderId = is_object($order) && method_exists($order, 'getKey')
                ? (string) $order->getKey()
                : (string) $order;
            $guestSession = trim((string) $request->header('X-Guest-Session', ''));
            $actor = $request->user()?->getAuthIdentifier() !== null
                ? 'user:'.$request->user()->getAuthIdentifier()
                : ($guestSession !== ''
                    ? 'guest:'.hash('sha256', $guestSession)
                    : 'ip:'.$request->ip());

            return Limit::perMinute((int) env('PAYMENT_ACTION_RATE_LIMIT_PER_MINUTE', 30))
                ->by("payment-action:{$orderId}:{$actor}")
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'طلبات الدفع متقاربة جدًا. انتظر لحظات ثم أعد المحاولة مرة واحدة.',
                    'code' => 'payment_rate_limited',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 1),
                ], 429, $headers));
        });

        RateLimiter::for('payment-status', function (Request $request): Limit {
            $order = $request->route('order');
            $orderId = is_object($order) && method_exists($order, 'getKey')
                ? (string) $order->getKey()
                : (string) $order;
            $guestSession = trim((string) $request->header('X-Guest-Session', ''));
            $actor = $request->user()?->getAuthIdentifier() !== null
                ? 'user:'.$request->user()->getAuthIdentifier()
                : ($guestSession !== ''
                    ? 'guest:'.hash('sha256', $guestSession)
                    : 'ip:'.$request->ip());

            return Limit::perMinute((int) env('PAYMENT_STATUS_RATE_LIMIT_PER_MINUTE', 120))
                ->by("payment-status:{$orderId}:{$actor}");
        });
    }
}
