<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100);
            $table->string('type', 30)->default('marketing')->index();
            $table->string('locale', 10)->default('ar');
            $table->string('title', 180);
            $table->text('body');
            $table->string('default_channel', 20)->default('in_app');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'locale'], 'notification_template_key_locale_unique');
        });

        Schema::create('notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('type', 30)->default('marketing')->index();
            $table->string('locale', 10)->default('ar');
            $table->string('title', 180);
            $table->text('body');
            $table->string('channel', 20)->default('both')->index();
            $table->string('audience', 30)->default('all')->index();
            $table->json('audience_ids')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->json('data')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('in_app_count')->default(0);
            $table->unsignedInteger('fcm_accepted_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->json('failure_summary')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('audience_built_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->unsignedInteger('chunks_completed_count')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_key', 230)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('guest_session_id')->nullable();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('marketing_enabled')->default(false);
            $table->timestamps();

            $table->foreign('guest_session_id')->references('id')->on('app_guest_sessions')->cascadeOnDelete();
            $table->index(['user_id', 'marketing_enabled'], 'notification_pref_user_marketing_idx');
            $table->index(['guest_session_id', 'marketing_enabled'], 'notification_pref_guest_marketing_idx');
        });

        Schema::create('notification_campaign_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('notification_campaigns')->cascadeOnDelete();
            $table->char('chunk_key', 64);
            $table->string('status', 30)->default('queued')->index();
            $table->json('recipients');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'chunk_key'], 'notification_campaign_chunk_unique');
        });

        Schema::create('notification_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('guest_session_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->text('token_ciphertext')->nullable();
            $table->char('device_id_hash', 64)->nullable()->index();
            $table->string('platform', 20);
            $table->string('app_version', 50)->nullable();
            $table->string('locale', 10)->default('ar');
            $table->boolean('permission_granted')->default(false)->index();
            $table->boolean('push_enabled')->default(true)->index();
            $table->boolean('marketing_enabled')->default(false)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('guest_session_id')->references('id')->on('app_guest_sessions')->cascadeOnDelete();
            $table->index(['user_id', 'revoked_at'], 'notification_device_user_active_idx');
            $table->index(['guest_session_id', 'revoked_at'], 'notification_device_guest_active_idx');
        });

        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('notification_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('guest_session_id')->nullable();
            $table->string('recipient_key', 230);
            $table->string('audience_role', 30)->nullable()->index();
            $table->string('status', 30)->default('queued')->index();
            $table->timestamp('in_app_stored_at')->nullable();
            $table->unsignedSmallInteger('push_devices_count')->default(0);
            $table->unsignedSmallInteger('fcm_accepted_count')->default(0);
            $table->unsignedSmallInteger('push_failed_count')->default(0);
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->foreign('guest_session_id')->references('id')->on('app_guest_sessions')->cascadeOnDelete();
            $table->unique(['campaign_id', 'recipient_key'], 'notification_campaign_recipient_unique');
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('notification_campaigns')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->unique()->constrained('notification_recipients')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('guest_session_id')->nullable();
            $table->string('type', 30)->default('transactional')->index();
            $table->string('locale', 10)->default('ar');
            $table->string('title', 180);
            $table->text('body');
            $table->string('image_url', 2048)->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->json('data')->nullable();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->foreign('guest_session_id')->references('id')->on('app_guest_sessions')->cascadeOnDelete();
            $table->index(['user_id', 'created_at'], 'in_app_notification_user_created_idx');
            $table->index(['guest_session_id', 'created_at'], 'in_app_notification_guest_created_idx');
        });

        Schema::create('notification_push_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $table->foreignId('device_token_id')->constrained('notification_device_tokens')->cascadeOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id', 500)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['recipient_id', 'device_token_id'], 'notification_recipient_device_unique');
        });

        $welcomeBodyAr = "✅ تم توثيق رقم جوالك بنجاح لمدة 10 أيام.\n🧡 تسوّق واطلب من الأسر المنتجة بكل سهولة،\nدون الحاجة لإعادة رمز التحقق خلال هذه المدة.\nزاد سينك — إبداع الأسر بين يديك.";
        $welcomeBodyEn = "✅ Your mobile number has been verified for 10 days.\n🧡 Shop and order from productive families with ease,\nwithout entering another verification code during this period.\nZAD Sync — productive families' creativity at your fingertips.";

        DB::table('notification_templates')->insert([
            [
                'key' => 'phone_verified_welcome',
                'type' => 'transactional',
                'locale' => 'ar',
                'title' => '🎉 أهلًا بك في زاد سينك!',
                'body' => $welcomeBodyAr,
                'default_channel' => 'in_app',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'phone_verified_welcome',
                'type' => 'transactional',
                'locale' => 'en',
                'title' => '🎉 Welcome to ZAD Sync!',
                'body' => $welcomeBodyEn,
                'default_channel' => 'in_app',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_push_attempts');
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notification_device_tokens');
        Schema::dropIfExists('notification_campaign_chunks');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_campaigns');
        Schema::dropIfExists('notification_templates');
    }
};
