<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('emergency_phone', 30)->nullable()->after('phone');
            $table->string('application_status', 30)->default('draft')->after('status');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
        });

        Schema::create('driver_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('path');
            $table->string('status', 30)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['driver_id', 'type']);
        });

        Schema::create('driver_profile_field_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label_ar');
            $table->string('label_en')->nullable();
            $table->string('field_type', 30)->default('text');
            $table->json('vehicle_types')->nullable();
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('driver_profile_field_settings')->insert([
            ['key' => 'birth_date', 'label_ar' => 'تاريخ الميلاد', 'field_type' => 'date', 'is_required' => false, 'is_active' => true, 'is_system' => false, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profile_field_settings');
        Schema::dropIfExists('driver_documents');
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['emergency_phone', 'application_status', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};