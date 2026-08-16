<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journey_retention_settings')) {
            Schema::create('journey_retention_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('completed_retention_hours')->default(16);
                $table->unsignedInteger('cancelled_retention_hours')->default(24);
                $table->unsignedInteger('problem_retention_hours')->default(168);
                $table->unsignedInteger('purge_batch_size')->default(50);
                // يبدأ متوقفًا للحماية، ويُفعّل بعد مراجعة المعاينة الأولى.
                $table->boolean('automatic_purge_enabled')->default(false);
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            DB::table('journey_retention_settings')->insert([
                'completed_retention_hours' => 16,
                'cancelled_retention_hours' => 24,
                'problem_retention_hours' => 168,
                'purge_batch_size' => 50,
                'automatic_purge_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('media_retention_status', 20)->default('pending')->index();
            $table->timestamp('media_delete_at')->nullable()->index();
            $table->boolean('media_retention_hold')->default(false)->index();
            $table->timestamp('media_hold_until')->nullable()->index();
            $table->text('media_retention_reason')->nullable();
            $table->timestamp('media_purged_at')->nullable();
            $table->foreignId('media_purged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('media_purge_attempts')->default(0);
            $table->text('media_purge_last_error')->nullable();
        });

        Schema::table('order_journey_proofs', function (Blueprint $table): void {
            $table->string('photo_checksum', 64)->nullable();
            $table->unsignedBigInteger('photo_size_bytes')->nullable();
            $table->string('photo_mime_type', 120)->nullable();
            $table->timestamp('photo_purged_at')->nullable();
        });

        Schema::table('order_journey_proofs', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->change();
        });

        Schema::table('order_live_sessions', function (Blueprint $table): void {
            $table->string('final_photo_checksum', 64)->nullable();
            $table->unsignedBigInteger('final_photo_size_bytes')->nullable();
            $table->string('final_photo_mime_type', 120)->nullable();
            $table->timestamp('final_photo_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_live_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'final_photo_checksum',
                'final_photo_size_bytes',
                'final_photo_mime_type',
                'final_photo_purged_at',
            ]);
        });

        Schema::table('order_journey_proofs', function (Blueprint $table): void {
            $table->dropColumn([
                'photo_checksum',
                'photo_size_bytes',
                'photo_mime_type',
                'photo_purged_at',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('media_purged_by_user_id');
            $table->dropColumn([
                'media_retention_status',
                'media_delete_at',
                'media_retention_hold',
                'media_hold_until',
                'media_retention_reason',
                'media_purged_at',
                'media_purge_attempts',
                'media_purge_last_error',
            ]);
        });

        Schema::dropIfExists('journey_retention_settings');
    }
};