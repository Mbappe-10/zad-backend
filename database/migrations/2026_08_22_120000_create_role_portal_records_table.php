<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_portal_records', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('role', 30)->index();
            $table->string('module', 50)->index();
            $table->string('owner_type')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedInteger('version')->nullable();
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['role', 'module', 'status']);
            $table->index(['owner_type', 'owner_id', 'module'], 'role_portal_owner_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_portal_records');
    }
};
