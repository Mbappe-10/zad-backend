<?php

namespace App\Services\Image;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductImageService
{
    public function __construct(
        private readonly ImageProcessingService $imageProcessor,
    ) {
    }
    /**
     * رفع صورة المنتج مباشرة بدون ذكاء اصطناعي أو قص أو ضغط أو فحص جودة.
     * الهدف في نسخة الإطلاق: حفظ الصورة كما رفعها المستخدم.
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
        $product->refresh();

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
        $newImagePath = null;
        $processedImage = null;
        $databaseUpdated = false;

        try {
            $processedImage = $this->imageProcessor->processProductImage(
                $file,
                $directory,
            );

            $newImagePath = ltrim(
                (string) $processedImage['path'],
                '/',
            );

            DB::transaction(function () use (
                $product,
                $newImagePath,
            ): void {
                $product->forceFill([
                    'images' => [
                        $newImagePath,
                    ],
                ])->save();
            });

            $databaseUpdated = true;
            $product->refresh();

            /*
             * لا نحذف الصورة القديمة إلا بعد نجاح حفظ الجديدة في قاعدة البيانات.
             */
            if (
                $oldImagePath !== null
                && $oldImagePath !== $newImagePath
                && filter_var($oldImagePath, FILTER_VALIDATE_URL) === false
            ) {
                try {
                    Storage::disk('public')->delete($oldImagePath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            return [
                'product_id' => $productId,
                'image_path' => $newImagePath,
                'image_url' => Storage::disk('public')->url($newImagePath),
                'size_bytes' => (int) $processedImage['size_bytes'],
                'size_kb' => (float) $processedImage['size_kb'],
                'width' => (int) $processedImage['width'],
                'height' => (int) $processedImage['height'],
                'mime_type' => (string) $processedImage['mime_type'],
            ];
        } catch (Throwable $exception) {
            /*
             * Delete the new object only when the database was NOT updated.
             * Once the DB points to the new image, a later cleanup failure
             * must never destroy that successfully saved image.
             */
            if (! $databaseUpdated && $newImagePath !== null) {
                try {
                    Storage::disk('public')->delete($newImagePath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
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
        $product->refresh();

        $oldImagePath = $this->firstImagePath(
            $product->images,
        );

        DB::transaction(function () use ($product): void {
            $product->forceFill([
                'images' => null,
            ])->save();
        });

        if (
            $oldImagePath !== null
            && filter_var($oldImagePath, FILTER_VALIDATE_URL) === false
        ) {
            try {
                Storage::disk('public')->delete($oldImagePath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
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
            ! is_string($firstImage)
            || trim($firstImage) === ''
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

