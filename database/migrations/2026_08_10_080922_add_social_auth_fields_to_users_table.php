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
             * حسابات Google / Apple لا تحتاج
             * بريدًا أو كلمة مرور داخل زاد.
             */
            $table->string('email')
                ->nullable()
                ->change();

            $table->string('password')
                ->nullable()
                ->change();

            /*
             * مزود الهوية.
             * google | apple
             */
            $table->string('auth_provider', 20)
                ->nullable()
                ->after('password');

            /*
             * المعرف الفريد القادم من Google / Apple.
             * هو الأساس الذي نتعرف به على المستخدم.
             */
            $table->string('provider_user_id', 191)
                ->nullable()
                ->after('auth_provider');

            /*
             * يمنع تكرار نفس حساب Google / Apple.
             */
            $table->unique(
                ['auth_provider', 'provider_user_id'],
                'users_provider_identity_unique',
            );

            $table->index(
                'auth_provider',
                'users_auth_provider_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(
                'users_provider_identity_unique',
            );

            $table->dropIndex(
                'users_auth_provider_index',
            );

            $table->dropColumn([
                'auth_provider',
                'provider_user_id',
            ]);
        });
    }
};