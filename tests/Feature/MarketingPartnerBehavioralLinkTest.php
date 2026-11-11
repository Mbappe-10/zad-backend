<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\LaunchAnalyticsController;
use App\Models\LaunchCreator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketingPartnerBehavioralLinkTest extends TestCase
{
    use RefreshDatabase;

    private function sync(LaunchCreator $creator): void
    {
        $controller = app(LaunchAnalyticsController::class);

        $method = new \ReflectionMethod(
            LaunchAnalyticsController::class,
            'syncCreatorDashboardUser'
        );

        $method->invoke($controller, $creator);
    }

    private function creator(string $email, string $code): LaunchCreator
    {
        return LaunchCreator::query()->create([
            'name' => 'Partner ' . $code,
            'email' => $email,
            'code' => $code,
            'status' => 'active',
        ]);
    }

    public function test_unmatched_email_does_not_link(): void
    {
        $creator = $this->creator(
            'missing@example.invalid',
            'STAGE49_A'
        );

        $this->sync($creator);

        $this->assertNull($creator->fresh()->user_id);
    }

    public function test_unverified_user_does_not_link(): void
    {
        $user = User::factory()->create([
            'email' => 'unverified@example.invalid',
            'email_verified_at' => null,
        ]);

        $creator = $this->creator(
            $user->email,
            'STAGE49_B'
        );

        $this->sync($creator);

        $this->assertNull($creator->fresh()->user_id);
    }

    public function test_non_partner_user_does_not_link(): void
    {
        $user = User::factory()->create([
            'email' => 'ordinary@example.invalid',
            'email_verified_at' => now(),
        ]);

        $creator = $this->creator(
            $user->email,
            'STAGE49_C'
        );

        $this->sync($creator);

        $this->assertNull($creator->fresh()->user_id);
    }

    public function test_inactive_creator_does_not_link(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.invalid',
            'email_verified_at' => now(),
        ]);

        $creator = $this->creator(
            $user->email,
            'STAGE49_D'
        );

        $creator->update(['status' => 'inactive']);

        $this->sync($creator);

        $this->assertNull($creator->fresh()->user_id);
    }
}