<?php

namespace App\Services\Image;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProductImageService
{
    public function __construct(
        private readonly ImageProcessingService $imageProcessor,
    ) {
    }

    /**
     * يرفع صورة واحدة للمنتج ويستبدل الصورة السابقة بأمان.
     *
     * @return array{
     *     product_id: int,
     *     image_path: string,
     *     image_url: string,
     *     size_bytes: int,
     *     size_kb: float,
     *     width: int,
     *     height: int,
     *     mime_type: string
     * }
     */
    public function replace(
        Product $product,
        UploadedFile $file,
    ): array {
        $store = DB::table('stores')
            ->where('id', $product->store_id)
            ->whereNull('deleted_at')
            ->first([
                'id',
                'productive_family_id',
            ]);

        if ($store === null) {
            throw new RuntimeException(
                'لا يمكن رفع صورة للمنتج لأن المتجر المرتبط به غير موجود.',
            );
        }

        $familyId = (int) $store->productive_family_id;
        $storeId = (int) $store->id;
        $productId = (int) $product->id;

        if ($familyId <= 0 || $storeId <= 0 || $productId <= 0) {
            throw new RuntimeException(
                'بيانات الأسرة أو المتجر أو المنتج غير صالحة.',
            );
        }

        $directory = sprintf(
            'productive-families/%d/stores/%d/products/%d',
            $familyId,
            $storeId,
            $productId,
        );

        $oldImagePath = $this->firstImagePath(
            $product->images,
        );

        $processed = null;

        try {
            $processed = $this->imageProcessor->processProductImage(
                $file,
                $directory,
            );

            DB::transaction(function () use (
                $product,
                $processed,
            ): void {
                $product->forceFill([
                    'images' => [
                        $processed['path'],
                    ],
                ])->save();
            });

            if (
                $oldImagePath !== null &&
                $oldImagePath !== $processed['path']
            ) {
                $this->imageProcessor->delete(
                    $oldImagePath,
                );
            }

            return [
                'product_id' => $productId,
                'image_path' => $processed['path'],
                'image_url' => asset(
                    'storage/'.$processed['path'],
                ),
                'size_bytes' => $processed['size_bytes'],
                'size_kb' => $processed['size_kb'],
                'width' => $processed['width'],
                'height' => $processed['height'],
                'mime_type' => $processed['mime_type'],
            ];
        } catch (Throwable $exception) {
            if (
                is_array($processed) &&
                isset($processed['path'])
            ) {
                $this->imageProcessor->delete(
                    $processed['path'],
                );
            }

            throw $exception;
        }
    }

    /**
     * حذف صورة المنتج الحالية من قاعدة البيانات ومن التخزين.
     */
    public function deleteProductImage(
        Product $product,
    ): void {
        $oldImagePath = $this->firstImagePath(
            $product->images,
        );

        DB::transaction(function () use ($product): void {
            $product->forceFill([
                'images' => null,
            ])->save();
        });

        if ($oldImagePath !== null) {
            $this->imageProcessor->delete(
                $oldImagePath,
            );
        }
    }

    /**
     * استخراج أول مسار صورة صالح من حقل images.
     */
    private function firstImagePath(
        mixed $images,
    ): ?string {
        if (is_string($images)) {
            $decoded = json_decode(
                $images,
                true,
            );

            if (json_last_error() === JSON_ERROR_NONE) {
                $images = $decoded;
            }
        }

        if (! is_array($images)) {
            return null;
        }

        $firstImage = $images[0] ?? null;

        if (
            ! is_string($firstImage) ||
            trim($firstImage) === ''
        ) {
            return null;
        }

        $cleanPath = ltrim(
            trim($firstImage),
            '/',
        );

        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr(
                $cleanPath,
                strlen('storage/'),
            );
        }

        return $cleanPath !== ''
            ? $cleanPath
            : null;
    }
}