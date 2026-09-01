<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehicles', 'commission_type')) {
                $table->string('commission_type', 30)->default('percentage');
            }

            if (! Schema::hasColumn('vehicles', 'commission_value')) {
                $table->decimal('commission_value', 8, 2)->default(20);
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('vehicles', 'commission_type')) {
                $columns[] = 'commission_type';
            }

            if (Schema::hasColumn('vehicles', 'commission_value')) {
                $columns[] = 'commission_value';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
