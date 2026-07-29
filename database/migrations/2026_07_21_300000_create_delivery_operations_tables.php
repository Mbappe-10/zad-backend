<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Vehicles
        |--------------------------------------------------------------------------
        */

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('type');

            $table->decimal('max_distance_km', 8, 2)->nullable();
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('per_km_fee', 10, 2)->default(0);

            $table->boolean('requires_box')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['type', 'is_active'],
                'vehicles_type_active_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Drivers Delivery Fields
        |--------------------------------------------------------------------------
        */

        Schema::table('drivers', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')
                ->nullable()
                ->after('city_id')
                ->constrained('vehicles')
                ->nullOnDelete();

            $table->boolean('is_online')
                ->default(false)
                ->after('status');

            $table->decimal('current_latitude', 10, 7)
                ->nullable();

            $table->decimal('current_longitude', 10, 7)
                ->nullable();

            $table->timestamp('location_updated_at')
                ->nullable();

            $table->unsignedInteger('active_orders_count')
                ->default(0);

            $table->index(
                ['city_id', 'is_online', 'status'],
                'drivers_availability_index'
            );

            $table->index(
                ['vehicle_id', 'is_online'],
                'drivers_vehicle_online_index'
            );

            $table->index(
                'location_updated_at',
                'drivers_location_updated_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Orders Delivery Fields
        |--------------------------------------------------------------------------
        */

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('delivery_zone_id')
                ->nullable()
                ->after('city_id')
                ->constrained('delivery_zones')
                ->nullOnDelete();

            $table->decimal('delivery_distance_km', 8, 2)
                ->nullable();

            $table->decimal('delivery_latitude', 10, 7)
                ->nullable();

            $table->decimal('delivery_longitude', 10, 7)
                ->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->index(
                ['status', 'created_at'],
                'orders_status_created_index'
            );

            $table->index(
                ['driver_id', 'status'],
                'orders_driver_status_index'
            );

            $table->index(
                ['city_id', 'status'],
                'orders_city_status_index'
            );

            $table->index(
                ['delivery_zone_id', 'status'],
                'orders_zone_status_index'
            );

            $table->index(
                ['payment_status', 'created_at'],
                'orders_payment_created_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Delivery Assignments
        |--------------------------------------------------------------------------
        */

        Schema::create('delivery_assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('driver_id')
                ->constrained('drivers')
                ->cascadeOnDelete();

            $table->string('status')
                ->default('offered');

            $table->decimal('distance_km', 8, 2)
                ->nullable();

            $table->decimal('score', 8, 2)
                ->default(0);

            $table->timestamp('offered_at')
                ->nullable();

            $table->timestamp('expires_at')
                ->nullable();

            $table->timestamp('responded_at')
                ->nullable();

            $table->text('rejection_reason')
                ->nullable();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /*
             * لم نستخدم unique على order_id + driver_id + status؛
             * لأن نفس الطلب قد يُعرض على المندوب مرة أخرى لاحقًا.
             */
            $table->index(
                ['order_id', 'driver_id'],
                'delivery_assignments_order_driver_index'
            );

            $table->index(
                ['driver_id', 'status'],
                'delivery_assignments_driver_status_index'
            );

            $table->index(
                ['order_id', 'status'],
                'delivery_assignments_order_status_index'
            );

            $table->index(
                ['status', 'expires_at'],
                'delivery_assignments_expiry_index'
            );

            $table->index(
                ['offered_at', 'responded_at'],
                'delivery_assignments_response_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Order Status Histories
        |--------------------------------------------------------------------------
        */

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->string('from_status')
                ->nullable();

            $table->string('to_status');

            $table->text('note')
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['order_id', 'created_at'],
                'order_histories_order_created_index'
            );

            $table->index(
                ['to_status', 'created_at'],
                'order_histories_status_created_index'
            );

            $table->index(
                ['changed_by', 'created_at'],
                'order_histories_user_created_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Delivery Pricing Rules
        |--------------------------------------------------------------------------
        */

        Schema::create('delivery_pricing_rules', function (Blueprint $table): void {
            $table->id();

            $table->string('name');

            $table->foreignId('city_id')
                ->nullable()
                ->constrained('cities')
                ->nullOnDelete();

            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete();

            $table->decimal('minimum_fee', 10, 2)
                ->default(0);

            $table->decimal('base_fee', 10, 2)
                ->default(0);

            $table->decimal('per_km_fee', 10, 2)
                ->default(0);

            $table->decimal('surge_multiplier', 6, 2)
                ->default(1);

            $table->unsignedInteger('priority')
                ->default(100);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['city_id', 'vehicle_id', 'is_active'],
                'delivery_pricing_lookup_index'
            );

            $table->index(
                ['is_active', 'priority'],
                'delivery_pricing_priority_index'
            );
        });
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Remove Dependent Tables First
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('delivery_pricing_rules');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('delivery_assignments');

        /*
        |--------------------------------------------------------------------------
        | Remove Orders Fields
        |--------------------------------------------------------------------------
        */

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_created_index');
            $table->dropIndex('orders_driver_status_index');
            $table->dropIndex('orders_city_status_index');
            $table->dropIndex('orders_zone_status_index');
            $table->dropIndex('orders_payment_created_index');

            $table->dropConstrainedForeignId('delivery_zone_id');

            $table->dropColumn([
                'delivery_distance_km',
                'delivery_latitude',
                'delivery_longitude',
                'accepted_at',
                'preparing_at',
                'ready_at',
                'picked_up_at',
                'delivered_at',
                'cancelled_at',
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Remove Drivers Fields
        |--------------------------------------------------------------------------
        */

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex('drivers_availability_index');
            $table->dropIndex('drivers_vehicle_online_index');
            $table->dropIndex('drivers_location_updated_index');

            $table->dropConstrainedForeignId('vehicle_id');

            $table->dropColumn([
                'is_online',
                'current_latitude',
                'current_longitude',
                'location_updated_at',
                'active_orders_count',
            ]);
        });

        Schema::dropIfExists('vehicles');
    }
};
