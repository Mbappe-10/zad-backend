<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\LaunchAnalyticsController;
use PHPUnit\Framework\TestCase;

class MarketingPartnerRoleLinkTest extends TestCase
{
    public function test_partner_linking_accepts_only_canonical_role(): void
    {
        $method = new \ReflectionMethod(
            LaunchAnalyticsController::class,
            'syncCreatorDashboardUser'
        );

        $file = file_get_contents($method->getFileName());
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        $body = implode(
            "\n",
            array_slice(explode("\n", $file), $start - 1, $end - $start + 1)
        );

        $this->assertStringContainsString(
            "hasRole('marketing_partner')",
            $body
        );

        $this->assertStringNotContainsString(
            "hasRole('marketing_company')",
            $body
        );

        $this->assertStringNotContainsString(
            "hasRole('شركة تسويقية')",
            $body
        );
    }
}