<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Platform Settings
        |--------------------------------------------------------------------------
        */

        Schema::create('platform_settings', function (Blueprint $table) {

            $table->id();

            // القسم
            $table->string('group',100)->index();

            // المفتاح
            $table->string('key',150);

            // القيمة
            $table->json('value')->nullable();

            // هل تعتبر قيمة حساسة
            $table->boolean('is_sensitive')->default(false);

            // آخر شخص عدل
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique([
                'group',
                'key'
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Platform Settings Audit
        |--------------------------------------------------------------------------
        */

        Schema::create('platform_setting_audits', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('group',100);

            $table->string('key',150);

            $table->json('old_value')->nullable();

            $table->json('new_value')->nullable();

            $table->string('ip_address',45)->nullable();

            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index([
                'group',
                'key'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_setting_audits');

        Schema::dropIfExists('platform_settings');
    }
};