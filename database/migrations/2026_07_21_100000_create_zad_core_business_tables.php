<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_active')->default(true);
            $table->decimal('delivery_base_fee', 10, 2)->default(0);
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('polygon')->nullable();
            $table->decimal('extra_fee', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('slug')->unique();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('productive_families', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('owner_name');
            $table->string('phone')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('health_certificate_number')->nullable();
            $table->date('health_certificate_expires_at')->nullable();
            $table->string('status')->default('pending')->index();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('productive_family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('is_open')->default(false);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->json('working_hours')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->string('status')->default('draft')->index();
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('preparation_minutes')->default(0);
            $table->json('images')->nullable();
            $table->json('variants')->nullable();
            $table->json('ingredients')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('identity_number')->nullable()->unique();
            $table->string('license_number')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('plate_number')->nullable();
            $table->string('status')->default('pending')->index();
            $table->decimal('rating', 3, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('payment_status')->default('unpaid')->index();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->json('delivery_address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->json('options')->nullable();
            $table->timestamps();
        });
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('currency', 3)->default('SAR');
            $table->decimal('available_balance', 14, 2)->default(0);
            $table->decimal('pending_balance', 14, 2)->default(0);
            $table->boolean('is_frozen')->default(false);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'currency']);
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('type')->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('status')->default('completed')->index();
            $table->nullableMorphs('related');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->nullableMorphs('approvable');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['security_events', 'approval_requests', 'wallet_transactions', 'wallets', 'order_items', 'orders', 'drivers', 'customers', 'products', 'stores', 'productive_families', 'categories', 'delivery_zones', 'cities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
