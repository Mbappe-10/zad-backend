<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireSponsoredCouponOwner;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SponsoredOwnerGuardTest extends TestCase
{
    private function checkAccess(?User $user): int
    {
        $request = Request::create('/internal/sponsored-owner-test', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new RequireSponsoredCouponOwner();

        try {
            $response = $middleware->handle(
                $request,
                fn () => response('allowed', 200)
            );

            return $response->getStatusCode();
        } catch (HttpException $exception) {
            return $exception->getStatusCode();
        }
    }

    public function test_platform_owner_is_allowed(): void
    {
        $owner = new User();
        $owner->exists = true;
        $owner->is_platform_owner = true;

        $this->assertSame(200, $this->checkAccess($owner));
    }

    public function test_non_owner_is_denied(): void
    {
        $admin = new User();
        $admin->exists = true;
        $admin->is_platform_owner = false;

        $this->assertSame(403, $this->checkAccess($admin));
    }

    public function test_guest_is_denied(): void
    {
        $this->assertSame(403, $this->checkAccess(null));
    }

    public function test_unsaved_user_is_denied(): void
    {
        $user = new User();
        $user->exists = false;
        $user->is_platform_owner = true;

        $this->assertSame(403, $this->checkAccess($user));
    }
}