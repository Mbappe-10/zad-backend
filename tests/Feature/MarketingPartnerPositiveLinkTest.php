<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\LaunchAnalyticsController;
use App\Models\LaunchCreator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketingPartnerPositiveLinkTest extends TestCase
{
    use RefreshDatabase;

    private function sync(LaunchCreator $creator): void
    {
        $method = new \ReflectionMethod(
            LaunchAnalyticsController::class,
            'syncCreatorDashboardUser'
        );

        $method->invoke(
            app(LaunchAnalyticsController::class),
            $creator
        );
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

    private function partner(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);

        $role = \App\Models\Role::query()->firstOrCreate(
            ['key' => 'marketing_partner'],
            [
                'name_ar' => 'شريك تسويقي',
                'name_en' => 'Marketing Partner',
                'is_system' => true,
                'is_active' => true,
                'priority' => 200,
            ]
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->unsetRelation('roles');

        return $user;
    }

    public function test_verified_partner_is_linked(): void
    {
        $user = $this->partner('valid@example.invalid');

        $creator = $this->creator(
            $user->email,
            'STAGE50_A'
        );

        $this->sync($creator);

        $this->assertEquals(
            $user->id,
            $creator->fresh()->user_id
        );
    }

    public function test_same_user_cannot_own_two_creators(): void
    {
        $user = $this->partner('shared@example.invalid');

        $first = $this->creator(
            $user->email,
            'STAGE50_B'
        );

        $second = $this->creator(
            $user->email,
            'STAGE50_C'
        );

        $this->sync($first);
        $this->sync($second);

        $this->assertEquals(
            $user->id,
            $first->fresh()->user_id
        );

        $this->assertNull(
            $second->fresh()->user_id
        );
    }
}