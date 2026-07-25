<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->index();
            $table->decimal('max_distance_km', 8, 2)->nullable();
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('per_km_fee', 10, 2)->default(0);
            $table->boolean('requires_box')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
            $table->boolean('is_online')->default(false)->after('status');
            $table->decimal('current_latitude', 10, 7)->nullable();
            $table->decimal('current_longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->unsignedInteger('active_orders_count')->default(0);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('delivery_zone_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
            $table->decimal('delivery_distance_km', 8, 2)->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::create('delivery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('offered')->index();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->decimal('score', 8, 2)->default(0);
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id','driver_id','status'], 'delivery_assignment_unique_status');
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->index();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('delivery_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('minimum_fee', 10, 2)->default(0);
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('per_km_fee', 10, 2)->default(0);
            $table->decimal('surge_multiplier', 6, 2)->default(1);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_pricing_rules');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('delivery_assignments');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn(['delivery_distance_km','delivery_latitude','delivery_longitude','accepted_at','preparing_at','ready_at','picked_up_at','delivered_at','cancelled_at']);
        });
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn(['is_online','current_latitude','current_longitude','location_updated_at','active_orders_count']);
        });
        Schema::dropIfExists('vehicles');
    }
};
