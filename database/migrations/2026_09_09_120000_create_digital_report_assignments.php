<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('digital_report_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('digital_employee_id')->constrained()->restrictOnDelete();
            $t->string('title', 200);
            $t->string('report_type', 30);
            $t->string('frequency', 20);
            $t->unsignedSmallInteger('interval_value')->default(1);
            $t->unsignedSmallInteger('period_days')->default(1);
            $t->string('timezone', 60)->default('Asia/Riyadh');
            $t->dateTime('starts_at'); // UTC; preserves monthly anchor day
            $t->dateTime('ends_at')->nullable(); // exclusive UTC boundary
            $t->dateTime('next_run_at')->nullable()->index();
            $t->string('status', 20)->default('active');
            $t->unsignedSmallInteger('reminder_hours')->default(24);
            $t->string('creation_key', 36);
            $t->unique(['owner_id', 'creation_key']);
            $t->timestamps();
        });
        Schema::create('digital_report_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('digital_report_assignments')->restrictOnDelete();
            $t->dateTime('scheduled_at');
            $t->unique(['assignment_id', 'scheduled_at']);
            $t->string('status', 25)->default('queued')->index();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->longText('snapshot')->nullable();
            $t->string('error_message')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->dateTime('reminder_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('digital_report_runs');
        Schema::dropIfExists('digital_report_assignments');
    }
};
