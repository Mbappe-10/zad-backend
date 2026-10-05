<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SecureContractPdfService
{
    private const CLOUDINARY_DELIVERY_TYPE = 'authenticated';

    private const R2_DISK = 'r2_contracts';

    private const R2_PROVIDER = 'r2';

    public function upload(
        UploadedFile $file,
        string $role,
        int $ownerId,
        int $version,
    ): array {
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw new RuntimeException('تعذر قراءة ملف العقد الموقع.');
        }

        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if ($mimeType !== 'application/pdf') {
            throw new RuntimeException('ملف العقد الموقع يجب أن يكون PDF.');
        }

        $rolePath = Str::slug($role) ?: 'account';

        $objectKey = implode('/', [
            'contracts',
            $rolePath,
            (string) $ownerId,
            'v'.$version,
            Str::uuid().'.pdf',
        ]);

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('تعذر فتح ملف العقد الموقع للأرشفة.');
        }

        try {
            $stored = Storage::disk(self::R2_DISK)->put(
                $objectKey,
                $stream,
            );
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                'تعذر أرشفة نسخة العقد الرسمية في Cloudflare R2.',
                previous: $exception,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new RuntimeException(
                'لم يؤكد Cloudflare R2 حفظ نسخة العقد الرسمية.',
            );
        }

        return [
            'provider' => self::R2_PROVIDER,
            'disk' => self::R2_DISK,

            // Kept for compatibility with the existing archive check.
            'public_id' => $objectKey,
            'object_key' => $objectKey,

            'asset_id' => null,
            'resource_type' => 'raw',
            'delivery_type' => 'private',
            'format' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => (int) ($file->getSize() ?? filesize($realPath) ?: 0),
            'sha256' => hash_file('sha256', $realPath),
        ];
    }

    public function download(array $meta): string
    {
        if ($this->isR2($meta)) {
            return $this->downloadFromR2($meta);
        }

        return $this->downloadFromCloudinary($meta);
    }

    public function delete(array $meta): void
    {
        if ($this->isR2($meta)) {
            $this->deleteFromR2($meta);

            return;
        }

        $this->deleteFromCloudinary($meta);
    }

    private function isR2(array $meta): bool
    {
        return ($meta['provider'] ?? null) === self::R2_PROVIDER
            || filled($meta['object_key'] ?? null);
    }

    private function downloadFromR2(array $meta): string
    {
        $objectKey = trim((string) (
            $meta['object_key']
            ?? $meta['public_id']
            ?? ''
        ));

        if ($objectKey === '') {
            throw new RuntimeException(
                'مرجع ملف العقد الرسمي في R2 غير موجود.',
            );
        }

        try {
            $pdf = Storage::disk(self::R2_DISK)->get($objectKey);
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                'تعذر قراءة نسخة العقد الرسمية من Cloudflare R2.',
                previous: $exception,
            );
        }

        if (! is_string($pdf) || $pdf === '') {
            throw new RuntimeException(
                'ملف العقد الرسمي في R2 فارغ أو غير صالح.',
            );
        }

        return $pdf;
    }

    private function deleteFromR2(array $meta): void
    {
        $objectKey = trim((string) (
            $meta['object_key']
            ?? $meta['public_id']
            ?? ''
        ));

        if ($objectKey === '') {
            return;
        }

        try {
            Storage::disk(self::R2_DISK)->delete($objectKey);
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                'تعذر حذف نسخة العقد الرسمية من Cloudflare R2.',
                previous: $exception,
            );
        }
    }

    private function downloadFromCloudinary(array $meta): string
    {
        $publicId = trim((string) ($meta['public_id'] ?? ''));
        $format = trim((string) ($meta['format'] ?? 'pdf')) ?: 'pdf';
        $resourceType = trim((string) ($meta['resource_type'] ?? 'raw')) ?: 'raw';
        $deliveryType = trim((string) (
            $meta['delivery_type']
            ?? self::CLOUDINARY_DELIVERY_TYPE
        )) ?: self::CLOUDINARY_DELIVERY_TYPE;

        if ($publicId === '') {
            throw new RuntimeException('مرجع ملف العقد الرسمي غير موجود.');
        }

        $url = $this->cloudinary()
            ->uploadApi()
            ->privateDownloadUrl(
                $publicId,
                $format,
                [
                    'resource_type' => $resourceType,
                    'type' => $deliveryType,
                    'attachment' => false,
                    'expires_at' => now()->addMinutes(5)->timestamp,
                ],
            );

        $response = Http::timeout(30)
            ->retry(2, 250)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                'تعذر قراءة نسخة العقد الرسمية من التخزين المحمي.',
            );
        }

        return $response->body();
    }

    private function deleteFromCloudinary(array $meta): void
    {
        $publicId = trim((string) ($meta['public_id'] ?? ''));

        if ($publicId === '') {
            return;
        }

        $this->cloudinary()->uploadApi()->destroy(
            $publicId,
            [
                'resource_type' => (string) (
                    $meta['resource_type']
                    ?? 'raw'
                ),
                'type' => (string) (
                    $meta['delivery_type']
                    ?? self::CLOUDINARY_DELIVERY_TYPE
                ),
                'invalidate' => true,
            ],
        );
    }

    private function cloudinary(): Cloudinary
    {
        $cloudinaryUrl = trim((string) config(
            'filesystems.disks.public.cloudinary_url'
        ));

        if ($cloudinaryUrl === '') {
            throw new RuntimeException(
                'CLOUDINARY_URL غير مضبوط في خدمة الباك إند.',
            );
        }

        return new Cloudinary($cloudinaryUrl);
    }
}