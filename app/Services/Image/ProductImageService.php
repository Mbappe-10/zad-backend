<?php

namespace App\Services\Image;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductImageService
{
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

        $extension = strtolower(
            $file->guessExtension()
                ?: $file->getClientOriginalExtension()
                ?: 'jpg',
        );

        $allowedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'webp',
            'gif',
        ];

        if (! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException(
                'صيغة الصورة غير مدعومة. استخدم JPG أو PNG أو WebP أو GIF.',
            );
        }

        $fileName = Str::uuid()->toString().'.'.$extension;
        $newImagePath = null;

        try {
            /*
             * نحفظ الملف مباشرة كما هو بدون Intervention Image.
             * هذا يلغي مشاكل المعالجة والضغط في نسخة الإطلاق.
             */
            $storedPath = $file->storeAs(
                $directory,
                $fileName,
                'public',
            );

            if (! is_string($storedPath) || trim($storedPath) === '') {
                throw new RuntimeException(
                    'تعذر حفظ صورة المنتج في التخزين.',
                );
            }

            $newImagePath = ltrim($storedPath, '/');

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

            $product->refresh();

            /*
             * لا نحذف الصورة القديمة إلا بعد نجاح حفظ الجديدة في قاعدة البيانات.
             */
            if (
                $oldImagePath !== null
                && $oldImagePath !== $newImagePath
            ) {
                Storage::disk('public')->delete(
                    $oldImagePath,
                );
            }

            $imageInformation = @getimagesize(
                $file->getRealPath(),
            );

            $width = is_array($imageInformation)
                ? (int) ($imageInformation[0] ?? 0)
                : 0;

            $height = is_array($imageInformation)
                ? (int) ($imageInformation[1] ?? 0)
                : 0;

            $sizeBytes = (int) ($file->getSize() ?: 0);

            return [
                'product_id' => $productId,
                'image_path' => $newImagePath,
                /*
                 * نعيد مسارًا نسبيًا بدل asset() حتى لا يحدث تعارض
                 * بين localhost و 127.0.0.1 في بيئة التطوير.
                 */
                'image_url' => '/storage/'.$newImagePath,
                'size_bytes' => $sizeBytes,
                'size_kb' => round(
                    $sizeBytes / 1024,
                    2,
                ),
                'width' => $width,
                'height' => $height,
                'mime_type' => (string) (
                    $file->getMimeType()
                    ?: $file->getClientMimeType()
                    ?: 'application/octet-stream'
                ),
            ];
        } catch (Throwable $exception) {
            /*
             * إذا فشل تحديث قاعدة البيانات بعد حفظ الملف، نحذف الملف الجديد.
             */
            if (
                $newImagePath !== null
                && Storage::disk('public')->exists($newImagePath)
            ) {
                Storage::disk('public')->delete(
                    $newImagePath,
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
        $product->refresh();

        $oldImagePath = $this->firstImagePath(
            $product->images,
        );

        DB::transaction(function () use ($product): void {
            $product->forceFill([
                'images' => null,
            ])->save();
        });

        if ($oldImagePath !== null) {
            Storage::disk('public')->delete(
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