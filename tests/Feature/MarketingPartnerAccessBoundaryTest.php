<?php

namespace Tests\Feature;

use App\Http\Middleware\DenyMarketingPartnerLegacyAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class MarketingPartnerAccessBoundaryTest extends TestCase
{
    public function test_unauthenticated_partner_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/partner/coupons')->assertUnauthorized();
        $this->getJson('/api/admin/partner/coupons/123/report')->assertUnauthorized();
    }

    public function test_unauthenticated_legacy_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/platform/coupons')->assertUnauthorized();
    }

    public function test_marketing_partner_is_blocked_by_legacy_middleware(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('marketing_partner')->andReturn(true);
        $request = Request::create('/api/platform/coupons', 'GET');
        $request->setUserResolver(fn () => $user);
        try {
            (new DenyMarketingPartnerLegacyAccess())->handle($request, fn () => new Response('allowed'));
            $this->fail('Expected HTTP 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_non_partner_is_not_blocked_by_partner_specific_middleware(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('marketing_partner')->andReturn(false);
        $request = Request::create('/api/platform/coupons', 'GET');
        $request->setUserResolver(fn () => $user);
        $response = (new DenyMarketingPartnerLegacyAccess())->handle($request, fn () => new Response('allowed', 200));
        $this->assertSame(200, $response->getStatusCode());
    }
}
