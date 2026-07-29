<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | البيانات الشخصية
            |--------------------------------------------------------------------------
            */

            $table->string('name_ar')
                ->nullable()
                ->after('name');

            $table->string('name_en')
                ->nullable()
                ->after('name_ar');

            $table->string('phone', 30)
                ->nullable()
                ->unique()
                ->after('email');

            $table->string('profile_photo')
                ->nullable()
                ->after('phone');

            /*
            |--------------------------------------------------------------------------
            | البيانات الوظيفية
            |--------------------------------------------------------------------------
            */

            $table->foreignId('department_id')
                ->nullable()
                ->after('profile_photo')
                ->constrained('departments')
                ->nullOnDelete();

            $table->foreignId('job_title_id')
                ->nullable()
                ->after('department_id')
                ->constrained('job_titles')
                ->nullOnDelete();

            $table->foreignId('manager_id')
                ->nullable()
                ->after('job_title_id')
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | حالة الحساب والاعتماد
            |--------------------------------------------------------------------------
            */

            $table->string('status', 30)
                ->default('pending')
                ->after('manager_id');

            $table->boolean('is_approved')
                ->default(false)
                ->after('status');

            $table->foreignId('approved_by')
                ->nullable()
                ->after('is_approved')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')
                ->nullable()
                ->after('approved_by');

            $table->timestamp('suspended_at')
                ->nullable()
                ->after('approved_at');

            $table->text('suspension_reason')
                ->nullable()
                ->after('suspended_at');

            /*
            |--------------------------------------------------------------------------
            | الإعدادات والأمان
            |--------------------------------------------------------------------------
            */

            $table->string('locale', 5)
                ->default('ar')
                ->after('suspension_reason');

            $table->string('timezone', 60)
                ->default('Asia/Riyadh')
                ->after('locale');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('timezone');

            $table->string('last_login_ip', 45)
                ->nullable()
                ->after('last_login_at');

            $table->boolean('mfa_enabled')
                ->default(false)
                ->after('last_login_ip');

            $table->timestamp('password_changed_at')
                ->nullable()
                ->after('mfa_enabled');

            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | الفهارس
            |--------------------------------------------------------------------------
            */

            $table->index([
                'department_id',
                'status',
            ]);

            $table->index([
                'job_title_id',
                'is_approved',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['job_title_id']);
            $table->dropForeign(['manager_id']);
            $table->dropForeign(['approved_by']);

            $table->dropIndex([
                'department_id',
                'status',
            ]);

            $table->dropIndex([
                'job_title_id',
                'is_approved',
            ]);

            $table->dropColumn([
                'name_ar',
                'name_en',
                'phone',
                'profile_photo',
                'department_id',
                'job_title_id',
                'manager_id',
                'status',
                'is_approved',
                'approved_by',
                'approved_at',
                'suspended_at',
                'suspension_reason',
                'locale',
                'timezone',
                'last_login_at',
                'last_login_ip',
                'mfa_enabled',
                'password_changed_at',
                'deleted_at',
            ]);
        });
    }
};
