<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_guest_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('device_id', 191)->nullable()->index();
            $table->string('push_token', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('permissions')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('purpose')->default('checkout')->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('guest_session_id', 36)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_journey_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('stage')->index(); // prepared, picked_up, delivered
            $table->string('photo_path');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'stage']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('guest_session_id', 36)->nullable()->after('customer_id')->index();
            $table->string('contact_phone', 20)->nullable()->after('guest_session_id')->index();
            $table->string('package_size', 20)->default('small')->after('delivery_zone_id');
            $table->string('recommended_vehicle_type', 30)->nullable()->after('package_size');
            $table->string('assigned_vehicle_type', 30)->nullable()->after('recommended_vehicle_type');
            $table->boolean('vehicle_rule_overridden')->default(false)->after('assigned_vehicle_type');
            $table->foreignId('vehicle_rule_overridden_by')->nullable()->after('vehicle_rule_overridden')->constrained('users')->nullOnDelete();
            $table->text('vehicle_override_reason')->nullable()->after('vehicle_rule_overridden_by');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('package_size', 20)->default('small')->after('preparation_minutes');
        });

        $defaults = [
            'auth.guest_browsing_enabled' => true,
            'auth.require_phone_at_checkout' => true,
            'auth.google_enabled' => false,
            'auth.apple_enabled' => false,
            'auth.email_enabled' => false,
            'auth.otp_channel' => 'sms',
            'permissions.ask_location_on_first_open' => true,
            'permissions.ask_notifications_on_first_open' => true,
            'journey.require_prepared_photo' => true,
            'journey.require_pickup_photo' => true,
            'journey.require_delivery_photo' => true,
            'journey.notify_customer_each_stage' => true,
            'subscriptions.enabled' => false,
            'subscriptions.launch_mode' => 'free_all_features',
            'delivery.vehicle_rules' => [
                ['vehicle' => 'scooter', 'max_distance_km' => 10, 'allowed_sizes' => ['small'], 'priority' => 10],
                ['vehicle' => 'motorcycle', 'max_distance_km' => 15, 'allowed_sizes' => ['small', 'medium'], 'priority' => 20],
                ['vehicle' => 'car', 'max_distance_km' => null, 'allowed_sizes' => ['small', 'medium', 'large', 'family'], 'priority' => 30],
            ],
            'delivery.owner_override_enabled' => true,
            'delivery.show_customer_phone_to_driver' => true,
            'delivery.show_customer_location_after_pickup' => true,
        ];

        foreach ($defaults as $key => $value) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'type' => is_bool($value) ? 'boolean' : (is_array($value) ? 'json' : 'string'), 'is_public' => !str_starts_with($key, 'delivery.owner_'), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('package_size'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_rule_overridden_by');
            $table->dropColumn(['guest_session_id','contact_phone','package_size','recommended_vehicle_type','assigned_vehicle_type','vehicle_rule_overridden','vehicle_override_reason']);
        });
        Schema::dropIfExists('order_journey_proofs');
        Schema::dropIfExists('phone_verifications');
        Schema::dropIfExists('app_guest_sessions');
    }
};
