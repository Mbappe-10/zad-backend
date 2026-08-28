<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->timestamp('sensitive_data_purged_at')->nullable();

            $table->index(
                ['pickup_latitude', 'pickup_longitude'],
                'orders_pickup_coordinates_index',
            );

            $table->index(
                'sensitive_data_purged_at',
                'orders_sensitive_data_purged_index',
            );
        });

        /* تعبئة الطلبات القديمة من موقع المتجر الحالي إن كان موجودًا. */
        DB::table('orders')
            ->select(['id', 'store_id'])
            ->whereNull('pickup_latitude')
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                $storeIds = $orders
                    ->pluck('store_id')
                    ->filter()
                    ->unique()
                    ->values();

                $stores = DB::table('stores')
                    ->whereIn('id', $storeIds)
                    ->get([
                        'id',
                        'pickup_address',
                        'pickup_latitude',
                        'pickup_longitude',
                    ])
                    ->keyBy('id');

                foreach ($orders as $order) {
                    $store = $stores->get($order->store_id);

                    if (
                        $store === null ||
                        $store->pickup_latitude === null ||
                        $store->pickup_longitude === null
                    ) {
                        continue;
                    }

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'pickup_address' => $store->pickup_address,
                            'pickup_latitude' => $store->pickup_latitude,
                            'pickup_longitude' => $store->pickup_longitude,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_pickup_coordinates_index');
            $table->dropIndex('orders_sensitive_data_purged_index');

            $table->dropColumn([
                'pickup_address',
                'pickup_latitude',
                'pickup_longitude',
                'sensitive_data_purged_at',
            ]);
        });
    }
};
