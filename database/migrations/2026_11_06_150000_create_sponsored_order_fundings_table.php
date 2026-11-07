<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("sponsored_order_fundings", function (Blueprint $table): void {
            $table->id();
            $table->foreignId("coupon_id")
                ->unique()
                ->constrained("sponsored_creator_coupons")
                ->restrictOnDelete();
            $table->foreignId("order_id")
                ->unique()
                ->constrained("orders")
                ->restrictOnDelete();
            $table->string("funding_source", 32)->default("zad_platform");
            $table->string("currency", 3)->default("SAR");
            $table->decimal("products_amount", 12, 2);
            $table->decimal("delivery_amount", 12, 2);
            $table->decimal("tax_amount", 12, 2)->default(0);
            $table->decimal("funded_total", 12, 2);
            $table->string("status", 24)->default("committed");
            $table->string("idempotency_key", 100)->unique();
            $table->json("calculation_snapshot")->nullable();
            $table->timestamp("committed_at");
            $table->timestamps();
            $table->index(["status", "committed_at"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("sponsored_order_fundings");
    }
};