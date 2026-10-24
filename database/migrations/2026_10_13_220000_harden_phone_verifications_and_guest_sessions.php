<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_guest_sessions', function (Blueprint $table): void {
            $table->char('session_token_hash', 64)->nullable()->unique();
            $table->timestamp('session_token_expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
        });

        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table
                ->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedTinyInteger('code_length')->default(6);
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('resend_available_at')->nullable();
            $table->timestamp('locked_until')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('verification_expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->char('device_hash', 64)->nullable()->index();
            $table->char('request_ip_hash', 64)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->char('send_idempotency_key', 64)->nullable()->unique();
            $table->string('failure_code', 100)->nullable();

            $table->index(
                ['phone', 'purpose', 'status'],
                'phone_verifications_phone_purpose_status_idx',
            );
            $table->index(
                ['guest_session_id', 'verification_expires_at'],
                'phone_verifications_guest_verified_idx',
            );
            $table->index(
                ['user_id', 'verification_expires_at'],
                'phone_verifications_user_verified_idx',
            );
        });

        Schema::create('phone_verification_send_counters', function (Blueprint $table): void {
            $table->id();
            $table->char('phone_hash', 64);
            $table->date('send_date');
            $table->unsignedTinyInteger('sends')->default(0);
            $table->timestamps();

            $table->unique(
                ['phone_hash', 'send_date'],
                'phone_verification_daily_send_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_send_counters');

        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->dropIndex('phone_verifications_phone_purpose_status_idx');
            $table->dropIndex('phone_verifications_guest_verified_idx');
            $table->dropIndex('phone_verifications_user_verified_idx');
            $table->dropUnique(['send_idempotency_key']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'status',
                'code_length',
                'sent_at',
                'resend_available_at',
                'locked_until',
                'last_attempt_at',
                'verification_expires_at',
                'revoked_at',
                'device_hash',
                'request_ip_hash',
                'provider',
                'provider_message_id',
                'send_idempotency_key',
                'failure_code',
            ]);
        });

        Schema::table('app_guest_sessions', function (Blueprint $table): void {
            $table->dropUnique(['session_token_hash']);
            $table->dropColumn([
                'session_token_hash',
                'session_token_expires_at',
                'revoked_at',
            ]);
        });
    }
};
