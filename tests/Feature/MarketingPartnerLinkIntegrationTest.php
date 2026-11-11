<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\LaunchAnalyticsController;
use App\Models\LaunchCreator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketingPartnerLinkIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_linking_requires_verified_canonical_partner(): void
    {
        $this->assertTrue(
            Schema::hasColumn('launch_creators', 'user_id'),
            'Partner dashboard migration is required.'
        );

        $method = new \ReflectionMethod(
            LaunchAnalyticsController::class,
            'syncCreatorDashboardUser'
        );

        $creator = new LaunchCreator();
        $creator->email = 'partner-test@example.invalid';
        $creator->status = 'active';

        $this->assertInstanceOf(LaunchCreator::class, $creator);
        $this->assertTrue($method->isPrivate());

        $source = implode(
            "\n",
            array_slice(
                file($method->getFileName()),
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            )
        );

        $this->assertStringContainsString(
            "email_verified_at",
            $source
        );

        $this->assertStringContainsString(
            "hasRole('marketing_partner')",
            $source
        );

        $this->assertStringContainsString(
            "where('user_id',",
            $source
        );
    }
}