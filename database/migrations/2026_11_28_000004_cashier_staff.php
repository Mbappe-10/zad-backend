<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad Pro staff: employees the family adds (each with a 4-digit PIN and
 * permissions), invite codes an employee scans from "My account", cashier devices
 * linked to the store (an iPad that stays locked until an employee enters her PIN),
 * and shifts with opening cash and counted cash. Sales remember who made them.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cashier_staff')) {
            Schema::create('cashier_staff', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('name', 60);
                $table->string('role', 20)->default('cashier'); // owner | cashier | packaging | inventory | supervisor
                $table->json('permissions')->nullable();
                $table->unsignedTinyInteger('max_discount_percent')->default(0);
                $table->string('pin_hash')->nullable();
                $table->string('color', 9)->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->boolean('active')->default(true);
                $table->timestamp('removed_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_staff_invites')) {
            Schema::create('cashier_staff_invites', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('staff_id')->index();
                $table->string('code', 12)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->unsignedBigInteger('used_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_devices')) {
            Schema::create('cashier_devices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('kind', 20)->default('cashier'); // cashier | menu | kitchen | customer
                $table->string('name', 60);
                $table->char('token_hash', 64)->unique();
                $table->json('settings')->nullable();
                // An employee's own phone, linked with her invite code: only she can open it.
                $table->unsignedBigInteger('personal_staff_id')->nullable()->index();
                $table->unsignedBigInteger('staff_id')->nullable();
                $table->char('staff_token_hash', 64)->nullable();
                $table->timestamp('unlocked_at')->nullable();
                $table->unsignedTinyInteger('failed_pins')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_shifts')) {
            Schema::create('cashier_shifts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('staff_id')->nullable()->index();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('staff_name', 60)->nullable();
                $table->decimal('opening_cash', 12, 2)->default(0);
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->decimal('counted_cash', 12, 2)->nullable();
                $table->decimal('expected_cash', 12, 2)->nullable();
                $table->json('summary')->nullable();
                $table->string('note', 200)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('sale_receipts')) {
            Schema::table('sale_receipts', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_receipts', 'cashier_staff_id')) {
                    $table->unsignedBigInteger('cashier_staff_id')->nullable()->index();
                }
                if (! Schema::hasColumn('sale_receipts', 'cashier_shift_id')) {
                    $table->unsignedBigInteger('cashier_shift_id')->nullable()->index();
                }
                if (! Schema::hasColumn('sale_receipts', 'staff_name')) {
                    $table->string('staff_name', 60)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_receipts')) {
            Schema::table('sale_receipts', function (Blueprint $table): void {
                foreach (['cashier_staff_id', 'cashier_shift_id', 'staff_name'] as $c) {
                    if (Schema::hasColumn('sale_receipts', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        Schema::dropIfExists('cashier_shifts');
        Schema::dropIfExists('cashier_devices');
        Schema::dropIfExists('cashier_staff_invites');
        Schema::dropIfExists('cashier_staff');
    }
};
