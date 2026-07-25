<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_profile_and_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Current-Password-2028!'),
            'status' => 'active',
            'is_approved' => true,
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'name_ar' => 'مدير منصة زاد',
            'name_en' => 'ZAD Platform Manager',
            'phone' => '0500000000',
            'locale' => 'ar',
            'timezone' => 'Asia/Riyadh',
        ])
            ->assertOk()
            ->assertJsonPath('user.nameAr', 'مدير منصة زاد')
            ->assertJsonPath('user.phone', '0500000000');

        $this->putJson('/api/profile/password', [
            'current_password' => 'Current-Password-2028!',
            'password' => 'Updated-Password-2028!',
            'password_confirmation' => 'Updated-Password-2028!',
        ])->assertOk();

        $this->assertTrue(
            Hash::check(
                'Updated-Password-2028!',
                $user->fresh()->password,
            ),
        );
    }

    public function test_user_can_upload_and_remove_profile_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'status' => 'active',
            'is_approved' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png', 320, 320),
        ])
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'user' => ['profilePhoto'],
            ]);

        $photo = $response->json('user.profilePhoto');
        $this->assertNotNull($photo);

        $this->deleteJson('/api/profile/photo')
            ->assertOk()
            ->assertJsonPath('user.profilePhoto', null);
    }

    public function test_running_seeders_again_does_not_reset_owner_password(): void
    {
        $this->seed();

        $owner = User::query()
            ->where('email', env('ZAD_OWNER_EMAIL', 'owner@zad.local'))
            ->firstOrFail();
        $owner->update([
            'password' => 'Owner-Custom-Password-2028!',
        ]);

        $this->seed();

        $this->assertTrue(
            Hash::check(
                'Owner-Custom-Password-2028!',
                $owner->fresh()->password,
            ),
        );
    }
}
