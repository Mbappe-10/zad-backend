<?php

namespace App\Services\Image;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

class ImageProcessingService
{
    private const MAX_OUTPUT_BYTES = 500 * 1024;

    private const OUTPUT_WIDTH = 1200;

    private const OUTPUT_HEIGHT = 1200;

    private const INITIAL_QUALITY = 85;

    private const MINIMUM_QUALITY = 45;

    private const QUALITY_STEP = 5;

    private const MINIMUM_SOURCE_WIDTH = 500;

    private const MINIMUM_SOURCE_HEIGHT = 500;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(
            Driver::class,
            autoOrientation: true,
            decodeAnimation: false,
            backgroundColor: 'ffffff',
            strip: true,
        );
    }

    /**
     * @return array{
     *     path: string,
     *     size_bytes: int,
     *     size_kb: float,
     *     width: int,
     *     height: int,
     *     mime_type: string
     * }
     */
    public function processProductImage(
        UploadedFile $file,
        string $directory,
    ): array {
        $realPath = $this->validateSourceImage($file);

        $savedPath = null;

        try {
            /*
             * Intervention Image v4:
             * قراءة الصورة من المسار المؤقت الحقيقي.
             */
            $image = $this->manager->decodePath($realPath);

            /*
             * قص وتوحيد الصورة تلقائيًا إلى مربع.
             */
            $image->cover(
                self::OUTPUT_WIDTH,
                self::OUTPUT_HEIGHT,
            );

            /*
             * تحسين خفيف وآمن للصورة.
             */
            $image->contrast(3);
            $image->sharpen(5);

            $encodedResult = $this->encodeWithinLimit($image);

            $cleanDirectory = trim($directory, '/\\');

            if ($cleanDirectory === '') {
                throw new RuntimeException(
                    'مسار حفظ صورة المنتج غير صالح.',
                );
            }

            $fileName = Str::uuid()->toString().'.webp';
            $savedPath = $cleanDirectory.'/'.$fileName;

            $saved = Storage::disk('public')->put(
                $savedPath,
                $encodedResult['contents'],
            );

            if (! $saved) {
                throw new RuntimeException(
                    'تعذر حفظ الصورة في السيرفر.',
                );
            }

            if (! Storage::disk('public')->exists($savedPath)) {
                throw new RuntimeException(
                    'تمت معالجة الصورة ولكن لم يتم العثور عليها بعد الحفظ.',
                );
            }

            $actualSize = Storage::disk('public')->size($savedPath);

            return [
                'path' => $savedPath,
                'size_bytes' => $actualSize,
                'size_kb' => round($actualSize / 1024, 2),
                'width' => self::OUTPUT_WIDTH,
                'height' => self::OUTPUT_HEIGHT,
                'mime_type' => 'image/webp',
            ];
        } catch (RuntimeException $exception) {
            if ($savedPath !== null) {
                Storage::disk('public')->delete($savedPath);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($savedPath !== null) {
                Storage::disk('public')->delete($savedPath);
            }

            report($exception);

            /*
             * أثناء التطوير نظهر السبب الحقيقي لتسهيل اكتشاف المشكلة.
             */
            $details = config('app.debug')
                ? ' السبب التقني: '.$exception->getMessage()
                : '';

            throw new RuntimeException(
                'تعذر معالجة الصورة. الرجاء اختيار صورة أخرى واضحة.'.$details,
                previous: $exception,
            );
        }
    }

    public function delete(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $cleanPath = ltrim(trim($path), '/\\');

        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr(
                $cleanPath,
                strlen('storage/'),
            );
        }

        if ($cleanPath !== '') {
            Storage::disk('public')->delete($cleanPath);
        }
    }

    /**
     * @return array{
     *     contents: string,
     *     size: int,
     *     quality: int
     * }
     */
    private function encodeWithinLimit(object $image): array
    {
        for (
            $quality = self::INITIAL_QUALITY;
            $quality >= self::MINIMUM_QUALITY;
            $quality -= self::QUALITY_STEP
        ) {
            /*
             * Intervention Image v4:
             * تحويل الصورة إلى WebP.
             */
            $encoded = $image->encodeUsingFormat(
                Format::WEBP,
                quality: $quality,
                strip: true,
            );

            $contents = (string) $encoded;
            $size = strlen($contents);

            if ($size <= 0) {
                throw new RuntimeException(
                    'نتج ملف صورة فارغ أثناء المعالجة.',
                );
            }

            if ($size <= self::MAX_OUTPUT_BYTES) {
                return [
                    'contents' => $contents,
                    'size' => $size,
                    'quality' => $quality,
                ];
            }
        }

        throw new RuntimeException(
            'تعذر ضغط الصورة إلى أقل من 500 كيلوبايت. الرجاء اختيار صورة أخرى.',
        );
    }

    private function validateSourceImage(
        UploadedFile $file,
    ): string {
        if (! $file->isValid()) {
            throw new RuntimeException(
                'تعذر قراءة الصورة المرفوعة.',
            );
        }

        $realPath = $file->getRealPath();

        if (
            ! is_string($realPath) ||
            $realPath === '' ||
            ! is_file($realPath) ||
            ! is_readable($realPath)
        ) {
            throw new RuntimeException(
                'تعذر الوصول إلى ملف الصورة المؤقت.',
            );
        }

        $imageInformation = @getimagesize($realPath);

        if ($imageInformation === false) {
            throw new RuntimeException(
                'الملف المرفوع ليس صورة صالحة.',
            );
        }

        $width = (int) ($imageInformation[0] ?? 0);
        $height = (int) ($imageInformation[1] ?? 0);
        $mimeType = $imageInformation['mime'] ?? null;

        if (
            $width < self::MINIMUM_SOURCE_WIDTH ||
            $height < self::MINIMUM_SOURCE_HEIGHT
        ) {
            throw new RuntimeException(
                'الصورة صغيرة. يجب ألا تقل أبعادها عن 500×500 بكسل.',
            );
        }

        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        if (
            ! is_string($mimeType) ||
            ! in_array($mimeType, $allowedMimeTypes, true)
        ) {
            throw new RuntimeException(
                'صيغة الصورة غير مدعومة. الصيغ المسموحة JPG وPNG وWebP.',
            );
        }

        return $realPath;
    }
}