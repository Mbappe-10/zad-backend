<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Legacy catalog media migration is intentionally disabled.
     *
     * Cloudinary is an external media storage provider, not a database table.
     * Existing media migration will be handled separately by an idempotent
     * Artisan command after deployment.
     */
    public function up(): void
    {
        // Intentionally left empty.
    }

    public function down(): void
    {
        // Intentionally left empty.
    }
};
