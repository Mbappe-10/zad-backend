<?php

namespace App\Services\Notifications;

use Illuminate\Validation\ValidationException;

class NotificationPayloadPolicy
{
    public function assertSafe(?string $imageUrl, ?string $actionUrl): void
    {
        if ($imageUrl !== null && $imageUrl !== '') {
            $this->assertSafeImage($imageUrl);
        }

        if ($actionUrl !== null && $actionUrl !== '') {
            $this->assertSafeAction($actionUrl);
        }
    }

    public function assertSafeImage(string $url): void
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw ValidationException::withMessages([
                'image_url' => ['The promotional image must be an HTTPS URL without embedded credentials.'],
            ]);
        }
    }

    public function assertSafeAction(string $url): void
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $this->assertAllowedPath($url);

            return;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            $this->invalidAction();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        if (in_array($scheme, (array) config('notifications.links.allowed_schemes', []), true)) {
            $host = trim((string) ($parts['host'] ?? ''));
            $this->assertAllowedPath('/'.ltrim($host.$path, '/'));

            return;
        }

        if ($scheme === 'https') {
            $host = strtolower(trim((string) ($parts['host'] ?? '')));
            $allowedHosts = array_map('strtolower', (array) config('notifications.links.allowed_web_hosts', []));
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

            if ($host !== '' && ($host === $appHost || in_array($host, $allowedHosts, true))) {
                return;
            }
        }

        $this->invalidAction();
    }

    private function assertAllowedPath(string $path): void
    {
        foreach ((array) config('notifications.links.allowed_app_paths', []) as $allowedPath) {
            if ($path === rtrim($allowedPath, '/') || str_starts_with($path, $allowedPath)) {
                return;
            }
        }

        $this->invalidAction();
    }

    private function invalidAction(): never
    {
        throw ValidationException::withMessages([
            'action_url' => ['The target must be an approved ZAD Sync path, deep link, or HTTPS host.'],
        ]);
    }
}
