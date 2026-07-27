<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetOwnerAccount extends Command
{
    protected $signature = 'zad:reset-owner';

    protected $description = 'Create or reset the ZAD platform owner account';

    public function handle(): int
    {
        $email = env('ZAD_OWNER_EMAIL', 'owner@zad.local');
        $password = 'Mbappe2018';

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'مالك منصة زاد',
                'name_ar' => 'مالك منصة زاد',
                'name_en' => 'ZAD Platform Owner',
                'password' => Hash::make($password),
                'status' => 'active',
                'is_approved' => true,
                'email_verified_at' => now(),
                'approved_at' => now(),
                'locale' => 'ar',
                'timezone' => 'Asia/Riyadh',
                'password_changed_at' => now(),
            ],
        );

        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles(['platform_owner']);
        } elseif ($user->isFillable('role')) {
            $user->forceFill([
                'role' => 'platform_owner',
            ])->save();
        }

        $this->info("Owner account updated successfully: {$user->email}");
        $this->info('Owner password has been reset successfully.');

        return self::SUCCESS;
    }
}