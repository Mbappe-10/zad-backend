<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
            $table->string('employee_number')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('employment_type')->default('full_time');
            $table->string('status')->default('active')->index();
            $table->date('hire_date')->nullable();
            $table->decimal('monthly_cost', 12, 2)->default(0);
            $table->json('skills')->nullable();
            $table->json('kpis')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('digital_task_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_employee_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_rule_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('running')->index();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('digital_employee_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0)->after('priority');
            $table->unsignedInteger('duration_ms')->nullable()->after('attempts');
            $table->text('error_message')->nullable()->after('output');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });

        Schema::table('digital_employees', function (Blueprint $table): void {
            $table->string('model_provider')->default('internal')->after('department');
            $table->string('model_name')->default('workflow-engine')->after('model_provider');
            $table->decimal('monthly_budget', 12, 2)->default(0)->after('autonomy_level');
            $table->decimal('spent_this_month', 12, 2)->default(0)->after('monthly_budget');
            $table->unsignedInteger('max_daily_tasks')->default(50)->after('spent_this_month');
        });
    }

    public function down(): void
    {
        Schema::table('digital_employees', function (Blueprint $table): void {
            $table->dropColumn(['model_provider','model_name','monthly_budget','spent_this_month','max_daily_tasks']);
        });
        Schema::table('digital_employee_tasks', function (Blueprint $table): void {
            $table->dropColumn(['attempts','duration_ms','error_message','cancelled_at']);
        });
        Schema::dropIfExists('automation_rule_runs');
        Schema::dropIfExists('digital_task_events');
        Schema::dropIfExists('employee_profiles');
    }
};
