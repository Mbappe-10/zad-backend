<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();

            $table->index(
                ['pickup_latitude', 'pickup_longitude'],
                'stores_pickup_coordinates_index',
            );
        });

        Schema::create('order_messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('sender_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('sender_role', 20);
            $table->string('message', 500);
            $table->timestamps();

            $table->index(
                ['order_id', 'id'],
                'order_messages_order_id_index',
            );

            $table->index(
                ['sender_user_id', 'created_at'],
                'order_messages_sender_index',
            );
        });

        Schema::create(
            'order_chat_purge_logs',
            function (Blueprint $table): void {
                $table->id();

                $table->string('mode', 20);
                $table->unsignedSmallInteger('retention_days');

                $table->unsignedBigInteger('eligible_orders')
                    ->default(0);

                $table->unsignedBigInteger('deleted_messages')
                    ->default(0);

                $table->foreignId('executed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['mode', 'created_at'],
                    'order_chat_purge_logs_mode_index',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_chat_purge_logs');
        Schema::dropIfExists('order_messages');

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex('stores_pickup_coordinates_index');

            $table->dropColumn([
                'pickup_address',
                'pickup_latitude',
                'pickup_longitude',
            ]);
        });
    }
};