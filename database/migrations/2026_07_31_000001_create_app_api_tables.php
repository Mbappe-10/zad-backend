<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | App Settings
        |--------------------------------------------------------------------------
        */

        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();

            $table
                ->string('key', 191)
                ->unique();

            $table
                ->json('value')
                ->nullable();

            $table
                ->string('type', 50)
                ->default('string')
                ->index();

            $table
                ->string('group', 100)
                ->default('general')
                ->index();

            $table
                ->boolean('is_public')
                ->default(true)
                ->index();

            $table
                ->text('description')
                ->nullable();

            $table
                ->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Application Profiles
        |--------------------------------------------------------------------------
        */

        Schema::create('app_profiles', function (Blueprint $table): void {
            $table->id();

            $table
                ->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table
                ->foreignId('productive_family_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table
                ->foreignId('driver_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table
                ->json('roles')
                ->nullable();

            $table
                ->string('active_mode', 50)
                ->default('customer')
                ->index();

            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Default Application Settings
        |--------------------------------------------------------------------------
        */

        $defaults = [
            [
                'key' => 'app.name',
                'value' => json_encode(
                    'زاد',
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
                ),
                'type' => 'string',
                'group' => 'branding',
                'description' => 'اسم التطبيق',
            ],
            [
                'key' => 'app.maintenance',
                'value' => json_encode(false),
                'type' => 'boolean',
                'group' => 'system',
                'description' => 'وضع الصيانة',
            ],
            [
                'key' => 'app.minimum_version',
                'value' => json_encode(
                    [
                        'android' => '1.0.0',
                        'ios' => '1.0.0',
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
                ),
                'type' => 'json',
                'group' => 'system',
                'description' => 'الحد الأدنى للإصدار',
            ],
            [
                'key' => 'registration.family_enabled',
                'value' => json_encode(true),
                'type' => 'boolean',
                'group' => 'registration',
                'description' => 'فتح التسجيل للأسر المنتجة',
            ],
            [
                'key' => 'registration.driver_enabled',
                'value' => json_encode(true),
                'type' => 'boolean',
                'group' => 'registration',
                'description' => 'فتح التسجيل للمندوبين',
            ],
            [
                'key' => 'orders.minimum_amount',
                'value' => json_encode(0),
                'type' => 'integer',
                'group' => 'orders',
                'description' => 'الحد الأدنى للطلب',
            ],
            [
                'key' => 'support.contact',
                'value' => json_encode(
                    [
                        'phone' => null,
                        'whatsapp' => null,
                        'email' => null,
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
                ),
                'type' => 'json',
                'group' => 'support',
                'description' => 'بيانات الدعم',
            ],
            [
                'key' => 'features',
                'value' => json_encode(
                    [
                        'coupons' => true,
                        'live_preparation' => true,
                        'wallets' => true,
                        'ratings' => true,
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
                ),
                'type' => 'json',
                'group' => 'features',
                'description' => 'خصائص التطبيق',
            ],
        ];

        foreach ($defaults as $row) {
            DB::table('app_settings')->insert([
                ...$row,
                'is_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_profiles');
        Schema::dropIfExists('app_settings');
    }
};