<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_interactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('promotion_id')->index();
            $table->string('event_type', 20)->index();
            $table->string('event_key', 64)->unique();
            $table->string('session_hash', 64)->index();
            $table->string('attribution_token_hash', 64)
                ->nullable()
                ->unique();
            $table->unsignedBigInteger('attributed_order_id')
                ->nullable()
                ->index();
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(
                ['promotion_id', 'event_type', 'occurred_at'],
                'promotion_interactions_lookup',
            );
        });

        Schema::create('promotion_conversions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('promotion_id')->index();
            $table->unsignedBigInteger('interaction_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->decimal('revenue', 12, 2)->default(0);
            $table->string('coupon_code', 80)->nullable();
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->timestamp('converted_at')->index();
            $table->timestamps();

            $table->index(
                ['promotion_id', 'converted_at'],
                'promotion_conversions_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_conversions');
        Schema::dropIfExists('promotion_interactions');
    }
};
