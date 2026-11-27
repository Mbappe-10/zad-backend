<?php

namespace Tests\Feature;

use App\Models\AppProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Probe: an ordinary app user (Google sign-in, no staff role) must not reach any
 * dashboard/admin/finance API. Prints every route that answered 2xx/422.
 */
class SecurityProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_app_user_cannot_reach_staff_apis(): void
    {
        $user = User::factory()->create();
        AppProfile::query()->create(['user_id' => $user->id, 'roles' => ['customer'], 'active_mode' => 'customer']);
        Sanctum::actingAs($user);
        $open = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || preg_match('#^api/(v1/app|v1/cashier-device|v1/screens|public|auth|r/)#', $uri)) {
                continue;
            }
            $mw = implode(',', $route->gatherMiddleware());
            if (! str_contains($mw, 'auth:sanctum')) {
                continue;
            }
            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                try {
                    $status = $this->json($method, $path, [])->getStatusCode();
                } catch (\Throwable $e) {
                    $status = 'EX';
                }
                if ($status === 'EX' || ($status >= 200 && $status < 300) || $status === 422 || $status === 500) {
                    $open[] = "$status $method $path";
                }
            }
        }
        fwrite(STDERR, "\nPROBE-OPEN-COUNT ".count($open)."\n".implode("\n", array_map(fn ($l) => 'PROBE '.$l, $open))."\n");
        $leaks = array_filter($open, fn ($l) => ! preg_match('#(/api/me|/api/profile|/api/auth/logout|/api/system/dictionaries|/api/digital-employee-order-tasks)#', $l));
        $this->assertSame([], array_values($leaks), 'An app token reached a dashboard API.');
    }

    public function test_dashboard_token_still_works_and_app_token_is_refused(): void
    {
        $staff = User::factory()->create();
        $dash = $staff->createToken(\App\Http\Middleware\EnsureDashboardToken::TOKEN_NAME)->plainTextToken;
        $app = $staff->createToken('zad-mobile-app')->plainTextToken;
        $this->withToken($app)->getJson('/api/finance/summary')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($app)->postJson('/api/finance/wallets/1/credit', ['amount' => 1000, 'type' => 'x', 'description' => 'x'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->assertNotSame(403, $this->withToken($dash)->getJson('/api/finance/summary')->getStatusCode());
    }
}
