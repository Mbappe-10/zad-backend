<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\LaunchAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Cookie;

class LaunchTrackingController extends Controller
{
    public function __construct(
        private readonly LaunchAttributionService $attribution,
    ) {
    }

    public function redirect(Request $request, string $code): RedirectResponse
    {
        $touch = $this->attribution->resolveTrackingCode($code);

        abort_if($touch === null, 404, 'رابط التتبع غير صالح أو أن الحملة غير نشطة.');

        $tokens = $this->attribution->touchSession(
            $request->cookie('zad_launch_visitor'),
            $request->cookie('zad_launch_session'),
            $touch,
        );

        $attributes = [...$touch, 'tracking_code' => $code];
        $this->attribution->recordEvent(
            $tokens['session'],
            'visit',
            $attributes,
        );

        if (($touch['medium'] ?? null) === 'qr') {
            $this->attribution->recordEvent(
                $tokens['session'],
                'qr_scan',
                $attributes,
            );
        }

        $url = $this->targetUrl($touch, $tokens['visitor_token'], $tokens['session_token']);
        $secure = $request->isSecure();

        return redirect()->away($url)
            ->withCookie(new Cookie(
                'zad_launch_visitor',
                $tokens['visitor_token'],
                now()->addYear(),
                '/',
                null,
                $secure,
                true,
                false,
                Cookie::SAMESITE_LAX,
            ))
            ->withCookie(new Cookie(
                'zad_launch_session',
                $tokens['session_token'],
                now()->addMinutes(30),
                '/',
                null,
                $secure,
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));
    }

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['nullable', 'string', 'max:128'],
            'event_type' => ['required', 'in:visit,qr_scan,store_view,product_view,add_to_cart,checkout'],
            'visitor_token' => ['nullable', 'string', 'max:128'],
            'session_token' => ['nullable', 'string', 'max:128'],
            'app_guest_session_id' => ['nullable', 'uuid', 'exists:app_guest_sessions,id'],
            'tracking_code' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:80'],
            'campaign' => ['nullable', 'string', 'max:80'],
            'creator' => ['nullable', 'string', 'max:80'],
            'booth' => ['nullable', 'string', 'max:80'],
            'family' => ['nullable', 'string', 'max:80'],
            'medium' => ['nullable', 'string', 'max:80'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'metadata' => ['nullable', 'array'],
        ]);

        $touch = $this->attribution->resolveParameters($data);
        $tokens = $this->attribution->touchSession(
            $data['visitor_token'] ?? $request->cookie('zad_launch_visitor'),
            $data['session_token'] ?? $request->cookie('zad_launch_session'),
            $touch,
            $data['app_guest_session_id'] ?? null,
        );
        $recorded = $this->attribution->recordEvent(
            $tokens['session'],
            $data['event_type'],
            [
                ...$touch,
                ...Arr::only($data, ['store_id', 'product_id', 'metadata']),
            ],
            $data['event_id'] ?? null,
        );

        return response()->json([
            'data' => [
                'recorded' => $recorded,
                'visitor_token' => $tokens['visitor_token'],
                'session_token' => $tokens['session_token'],
                'expires_at' => $tokens['session']->expires_at?->toIso8601String(),
            ],
        ], $recorded ? 201 : 200);
    }

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'string', 'max:128'],
            'app_guest_session_id' => ['nullable', 'uuid', 'exists:app_guest_sessions,id'],
        ]);
        $session = $this->attribution->claim(
            $data['session_token'],
            (int) $request->user()->id,
            $data['app_guest_session_id'] ?? null,
        );

        return response()->json([
            'message' => $session === null
                ? 'لم يتم العثور على جلسة تدشين صالحة.'
                : 'تم ربط مصدر التدشين بالمستخدم.',
            'linked' => $session !== null,
        ], $session === null ? 404 : 200);
    }

    /** @param array<string, mixed> $touch */
    private function targetUrl(
        array $touch,
        string $visitorToken,
        string $sessionToken,
    ): string {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = trim((string) ($touch['landing_path'] ?? '/'));
        $path = str_starts_with($path, '/') ? $path : '/';
        $parts = parse_url($path);
        $parameters = [];

        if (filled($parts['query'] ?? null)) {
            parse_str((string) $parts['query'], $parameters);
        }

        $parameters = array_filter([
            ...$parameters,
            'source' => $touch['source'] ?? 'launch',
            'campaign' => $touch['campaign_code'] ?? null,
            'creator' => $touch['creator_code'] ?? null,
            'booth' => $touch['booth'] ?? null,
            'family' => $touch['family_id'] ?? null,
            'medium' => $touch['medium'] ?? null,
            'zlv' => $visitorToken,
            'zls' => $sessionToken,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $pathname = (string) ($parts['path'] ?? '/');

        return $base.$pathname.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
