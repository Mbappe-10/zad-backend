<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('digital_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('job_title_ar');
            $table->string('job_title_en')->nullable();
            $table->string('department')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('risk_level')->default('medium');
            $table->unsignedTinyInteger('autonomy_level')->default(1);
            $table->boolean('requires_approval')->default(true);
            $table->json('capabilities')->nullable();
            $table->json('permissions')->nullable();
            $table->json('kpis')->nullable();
            $table->text('system_prompt')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('digital_employee_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('instructions');
            $table->string('status')->default('queued')->index();
            $table->string('priority')->default('medium');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('trigger_type');
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('digital_employee_tasks');
        Schema::dropIfExists('digital_employees');
    }
};
