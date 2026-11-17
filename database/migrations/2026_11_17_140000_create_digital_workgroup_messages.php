<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_workgroup_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('kind', 12);
            $table->string('author', 200);
            $table->string('recipient', 200)->nullable();
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->timestamp('created_at');
            $table->index(['owner_id', 'channel', 'id'], 'dwg_channel_messages');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_workgroup_messages');
    }
};
