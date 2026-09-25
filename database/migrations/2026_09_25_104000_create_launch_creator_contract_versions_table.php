<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_creator_contract_versions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('launch_campaign_creator_id')
                ->constrained('launch_campaign_creators')
                ->restrictOnDelete();

            $table->unsignedInteger('version');

            $table->string('contract_reference', 100)->nullable();
            $table->string('contract_title')->nullable();
            $table->longText('contract_content')->nullable();

            $table->timestamp('contract_starts_at')->nullable();
            $table->timestamp('contract_ends_at')->nullable();

            $table->string('contract_status', 24)->index();

            $table->string('coupon_code', 80)->nullable()->index();

            $table->string('commission_type', 24)->nullable();
            $table->decimal('commission_value', 14, 4)->nullable();

            $table->json('snapshot');

            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['launch_campaign_creator_id', 'version'],
                'creator_contract_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_creator_contract_versions');
    }
};