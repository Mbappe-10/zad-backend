<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('launch_campaign_creators', function (Blueprint $table): void {
            $table->string('contract_reference', 100)->nullable()->unique();
            $table->string('contract_title')->nullable();
            $table->longText('contract_content')->nullable();

            $table->timestamp('contract_starts_at')->nullable();
            $table->timestamp('contract_ends_at')->nullable();

            $table->string('contract_status', 24)
                ->default('draft')
                ->index();

            $table->unsignedInteger('contract_version')->default(1);

            $table->string('coupon_code', 80)
                ->nullable()
                ->unique();

            $table->timestamp('contract_cancelled_at')->nullable();
            $table->timestamp('contract_updated_at')->nullable();

            $table->json('contract_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('launch_campaign_creators', function (Blueprint $table): void {
            $table->dropColumn([
                'contract_reference',
                'contract_title',
                'contract_content',
                'contract_starts_at',
                'contract_ends_at',
                'contract_status',
                'contract_version',
                'coupon_code',
                'contract_cancelled_at',
                'contract_updated_at',
                'contract_snapshot',
            ]);
        });
    }
};