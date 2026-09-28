<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Replace only known legacy Render catalog media.
         *
         * This migration intentionally does not touch any other product
         * images or store logos. If a record has already been changed,
         * it is left untouched.
         */

        $productMedia = [
            'https://zad-backend-w2a2.onrender.com/catalog/products/47173387-5607-4098-a0f6-a2e0ddaf1794.jpg'
                => 'https://res.cloudinary.com/ew202rth/image/upload/v1790570195/zad-sync/migrated/products/47173387-5607-4098-a0f6-a2e0ddaf1794.jpg',

            'https://zad-backend-w2a2.onrender.com/catalog/products/a02a8497-6012-4ad2-9e0e-888a1e1123a1.png'
                => 'https://res.cloudinary.com/ew202rth/image/upload/v1790570228/zad-sync/migrated/products/a02a8497-6012-4ad2-9e0e-888a1e1123a1.png',

            'https://zad-backend-w2a2.onrender.com/catalog/products/c9168de9-efb2-42c3-8244-9a4120de4e36.png'
                => 'https://res.cloudinary.com/ew202rth/image/upload/v1790570205/zad-sync/migrated/products/c9168de9-efb2-42c3-8244-9a4120de4e36.png',

            'https://zad-backend-w2a2.onrender.com/catalog/products/f1980c6b-575d-4458-8a67-e14d77e0bb88.png'
                => 'https://res.cloudinary.com/ew202rth/image/upload/v1790570215/zad-sync/migrated/products/f1980c6b-575d-4458-8a67-e14d77e0bb88.png',
        ];

        DB::table('products')
            ->select(['id', 'images'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($productMedia): void {
                foreach ($products as $product) {
                    $images = json_decode(
                        (string) $product->images,
                        true,
                    );

                    if (! is_array($images)) {
                        continue;
                    }

                    $changed = false;

                    array_walk_recursive(
                        $images,
                        function (&$value) use ($productMedia, &$changed): void {
                            if (
                                is_string($value) &&
                                isset($productMedia[$value])
                            ) {
                                $value = $productMedia[$value];
                                $changed = true;
                            }
                        },
                    );

                    if (! $changed) {
                        continue;
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'images' => json_encode(
                                $images,
                                JSON_UNESCAPED_SLASHES |
                                JSON_UNESCAPED_UNICODE,
                            ),
                            'updated_at' => now(),
                        ]);
                }
            });

        $oldStoreLogo =
            'https://zad-backend-w2a2.onrender.com/catalog/stores/tfuYfTVm5sNJgSK2ydCWFGRMxaL4W1A2zt7SIYTb.png';

        $newStoreLogo =
            'https://res.cloudinary.com/ew202rth/image/upload/v1790570238/zad-sync/migrated/stores/tfuYfTVm5sNJgSK2ydCWFGRMxaL4W1A2zt7SIYTb.png';

        DB::table('stores')
            ->where('logo_path', $oldStoreLogo)
            ->update([
                'logo_path' => $newStoreLogo,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        /*
         * Intentionally irreversible.
         *
         * We do not restore legacy Render URLs because those files are
         * being retired from the public media path.
         */
    }
};