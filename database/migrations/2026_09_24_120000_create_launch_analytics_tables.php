<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 80)->unique();
            $table->string('tracking_code', 80)->unique();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('landing_path', 500)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'ends_at'], 'launch_campaign_status_dates_idx');
            $table->index(['city_id', 'status'], 'launch_campaign_city_status_idx');
            $table->index('created_at', 'launch_campaign_created_idx');
        });

        Schema::create('launch_creators', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('handle')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at'], 'launch_creator_status_created_idx');
        });

        Schema::create('launch_campaign_creators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('launch_campaigns')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('launch_creators')->cascadeOnDelete();
            $table->string('tracking_code', 80)->unique();
            $table->string('commission_type', 24)->default('percentage');
            $table->decimal('commission_value', 12, 4)->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();

            $table->unique(['campaign_id', 'creator_id'], 'launch_campaign_creator_unique');
            $table->index(['campaign_id', 'status'], 'launch_cc_campaign_status_idx');
            $table->index(['creator_id', 'status'], 'launch_cc_creator_status_idx');
        });

        Schema::create('launch_campaign_families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('launch_campaigns')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained('productive_families')->cascadeOnDelete();
            $table->string('booth_code', 80)->nullable();
            $table->string('tracking_code', 80)->unique();
            $table->string('status', 24)->default('active');
            $table->timestamps();

            $table->unique(['campaign_id', 'family_id'], 'launch_campaign_family_unique');
            $table->index(['campaign_id', 'booth_code'], 'launch_cf_campaign_booth_idx');
            $table->index(['family_id', 'status'], 'launch_cf_family_status_idx');
        });

        Schema::create('launch_attribution_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('visitor_hash', 64);
            $table->char('session_hash', 64)->unique();
            $table->uuid('app_guest_session_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('first_campaign_id')->nullable()->constrained('launch_campaigns')->nullOnDelete();
            $table->foreignId('first_creator_id')->nullable()->constrained('launch_creators')->nullOnDelete();
            $table->foreignId('first_family_id')->nullable()->constrained('productive_families')->nullOnDelete();
            $table->string('first_source', 80)->nullable();
            $table->string('first_medium', 80)->nullable();
            $table->string('first_booth', 80)->nullable();

            $table->foreignId('last_campaign_id')->nullable()->constrained('launch_campaigns')->nullOnDelete();
            $table->foreignId('last_creator_id')->nullable()->constrained('launch_creators')->nullOnDelete();
            $table->foreignId('last_family_id')->nullable()->constrained('productive_families')->nullOnDelete();
            $table->string('last_source', 80)->nullable();
            $table->string('last_medium', 80)->nullable();
            $table->string('last_booth', 80)->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('visitor_hash', 'launch_session_visitor_idx');
            $table->index('app_guest_session_id', 'launch_session_guest_idx');
            $table->index('user_id', 'launch_session_user_idx');
            $table->index(['last_campaign_id', 'last_seen_at'], 'launch_session_campaign_seen_idx');
            $table->index(['last_creator_id', 'last_seen_at'], 'launch_session_creator_seen_idx');
            $table->index(['last_family_id', 'last_seen_at'], 'launch_session_family_seen_idx');
            $table->index(['last_source', 'last_seen_at'], 'launch_session_source_seen_idx');
            $table->index('created_at', 'launch_session_created_idx');
        });

        Schema::create('launch_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('event_key', 64)->unique();
            $table->uuid('session_id')->nullable();
            $table->char('visitor_hash', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('launch_campaigns')->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('launch_creators')->nullOnDelete();
            $table->foreignId('family_id')->nullable()->constrained('productive_families')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('source', 80)->nullable();
            $table->string('medium', 80)->nullable();
            $table->string('booth', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('launch_attribution_sessions')->nullOnDelete();
            $table->index(['event_type', 'occurred_at'], 'launch_event_type_time_idx');
            $table->index(['campaign_id', 'occurred_at'], 'launch_event_campaign_time_idx');
            $table->index(['creator_id', 'occurred_at'], 'launch_event_creator_time_idx');
            $table->index(['family_id', 'occurred_at'], 'launch_event_family_time_idx');
            $table->index(['user_id', 'occurred_at'], 'launch_event_user_time_idx');
            $table->index(['order_id', 'occurred_at'], 'launch_event_order_time_idx');
            $table->index(['source', 'occurred_at'], 'launch_event_source_time_idx');
            $table->index('visitor_hash', 'launch_event_visitor_idx');
            $table->index('created_at', 'launch_event_created_idx');
        });

        Schema::create('launch_order_attributions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('session_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('family_id')->nullable()->constrained('productive_families')->nullOnDelete();

            $table->foreignId('first_campaign_id')->nullable()->constrained('launch_campaigns')->nullOnDelete();
            $table->foreignId('first_creator_id')->nullable()->constrained('launch_creators')->nullOnDelete();
            $table->foreignId('first_family_id')->nullable()->constrained('productive_families')->nullOnDelete();
            $table->string('first_source', 80)->nullable();
            $table->string('first_medium', 80)->nullable();
            $table->string('first_booth', 80)->nullable();

            $table->foreignId('last_campaign_id')->nullable()->constrained('launch_campaigns')->nullOnDelete();
            $table->foreignId('last_creator_id')->nullable()->constrained('launch_creators')->nullOnDelete();
            $table->foreignId('last_family_id')->nullable()->constrained('productive_families')->nullOnDelete();
            $table->string('last_source', 80)->nullable();
            $table->string('last_medium', 80)->nullable();
            $table->string('last_booth', 80)->nullable();

            $table->string('creator_commission_type', 24)->nullable();
            $table->decimal('creator_commission_rate', 12, 4)->default(0);
            $table->decimal('creator_commission_amount', 14, 2)->default(0);
            $table->string('creator_commission_status', 24)->default('pending');
            $table->timestamp('attributed_at');
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('launch_attribution_sessions')->nullOnDelete();
            $table->index(['last_campaign_id', 'attributed_at'], 'launch_order_campaign_time_idx');
            $table->index(['last_creator_id', 'attributed_at'], 'launch_order_creator_time_idx');
            $table->index(['family_id', 'attributed_at'], 'launch_order_family_time_idx');
            $table->index(['user_id', 'attributed_at'], 'launch_order_user_time_idx');
            $table->index(['last_source', 'attributed_at'], 'launch_order_source_time_idx');
            $table->index('created_at', 'launch_order_created_idx');
        });

        $this->seedPermissions();
    }

    public function down(): void
    {
        foreach (['launch_order_attributions', 'launch_events', 'launch_attribution_sessions', 'launch_campaign_families', 'launch_campaign_creators', 'launch_creators', 'launch_campaigns'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('key', [
                'view_launch_analytics',
                'manage_launch_campaigns',
            ])->delete();
        }
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $definitions = [
            'view_launch_analytics' => [
                'action' => 'view',
                'name_ar' => 'عرض تحليلات التدشين',
                'name_en' => 'View launch analytics',
            ],
            'manage_launch_campaigns' => [
                'action' => 'manage',
                'name_ar' => 'إدارة حملات التدشين',
                'name_en' => 'Manage launch campaigns',
            ],
        ];

        foreach ($definitions as $key => $definition) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'module' => 'launch_analytics',
                    'action' => $definition['action'],
                    'name_ar' => $definition['name_ar'],
                    'name_en' => $definition['name_en'],
                    'description_ar' => null,
                    'description_en' => null,
                    'is_sensitive' => false,
                    'requires_approval' => false,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys($definitions))
            ->pluck('id');
        $roleIds = DB::table('roles')
            ->whereIn('key', ['platform_owner', 'super_admin', 'general_manager', 'marketing_manager'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
