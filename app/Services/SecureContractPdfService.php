<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SecureContractPdfService
{
    private const DELIVERY_TYPE = 'authenticated';

    public function upload(UploadedFile $file, string $role, int $ownerId, int $version): array
    {
        $cloudinary = $this->cloudinary();
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw new RuntimeException('تعذر قراءة ملف العقد الموقع.');
        }

        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        if ($mimeType !== 'application/pdf') {
            throw new RuntimeException('ملف العقد الموقع يجب أن يكون PDF.');
        }

        $prefix = trim((string) config('filesystems.disks.public.cloudinary_prefix', 'zad-sync'), '/');
        $folder = implode('/', array_filter([
            $prefix,
            'secure',
            'signed-contracts',
            Str::slug($role) ?: 'account',
            (string) $ownerId,
            'v'.$version,
        ]));
        $publicId = $folder.'/'.Str::uuid().'.pdf';

        try {
            $response = $cloudinary->uploadApi()->upload($realPath, [
                'public_id' => $publicId,
                'resource_type' => 'raw',
                'type' => self::DELIVERY_TYPE,
                'overwrite' => false,
                'discard_original_filename' => true,
                'tags' => ['zad-sync', 'signed-contract', 'sensitive'],
            ])->getArrayCopy();
        } catch (Throwable $exception) {
            report($exception);
            throw new RuntimeException('تعذر أرشفة نسخة العقد الرسمية في التخزين المحمي.', previous: $exception);
        }

        $uploadedPublicId = trim((string) ($response['public_id'] ?? ''));
        if ($uploadedPublicId === '') {
            throw new RuntimeException('لم يرجع التخزين مرجعًا صالحًا للعقد الموقع.');
        }

        return [
            'public_id' => $uploadedPublicId,
            'asset_id' => filled($response['asset_id'] ?? null) ? (string) $response['asset_id'] : null,
            'resource_type' => (string) ($response['resource_type'] ?? 'raw'),
            'delivery_type' => (string) ($response['type'] ?? self::DELIVERY_TYPE),
            'format' => (string) ($response['format'] ?? 'pdf'),
            'mime_type' => 'application/pdf',
            'size' => (int) ($response['bytes'] ?? $file->getSize() ?? 0),
            'sha256' => hash_file('sha256', $realPath),
        ];
    }

    public function download(array $meta): string
    {
        $publicId = trim((string) ($meta['public_id'] ?? ''));
        $format = trim((string) ($meta['format'] ?? 'pdf')) ?: 'pdf';
        $resourceType = trim((string) ($meta['resource_type'] ?? 'raw')) ?: 'raw';
        $deliveryType = trim((string) ($meta['delivery_type'] ?? self::DELIVERY_TYPE)) ?: self::DELIVERY_TYPE;

        if ($publicId === '') {
            throw new RuntimeException('مرجع ملف العقد الرسمي غير موجود.');
        }

        $url = $this->cloudinary()->uploadApi()->privateDownloadUrl(
            $publicId,
            $format,
            [
                'resource_type' => $resourceType,
                'type' => $deliveryType,
                'attachment' => false,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ],
        );

        $response = Http::timeout(30)->retry(2, 250)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('تعذر قراءة نسخة العقد الرسمية من التخزين المحمي.');
        }

        $bytes = $response->body();
        if ($bytes === '' || ! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('نسخة العقد المؤرشفة ليست ملف PDF صالحًا.');
        }

        return $bytes;
    }

    public function delete(array $meta): void
    {
        $publicId = trim((string) ($meta['public_id'] ?? ''));
        if ($publicId === '') return;

        $this->cloudinary()->uploadApi()->destroy($publicId, [
            'resource_type' => (string) ($meta['resource_type'] ?? 'raw'),
            'type' => (string) ($meta['delivery_type'] ?? self::DELIVERY_TYPE),
            'invalidate' => true,
        ]);
    }

    private function cloudinary(): Cloudinary
    {
        $cloudinaryUrl = trim((string) config('filesystems.disks.public.cloudinary_url'));
        if ($cloudinaryUrl === '') {
            throw new RuntimeException('CLOUDINARY_URL غير مضبوط في خدمة الباك إند.');
        }

        return new Cloudinary($cloudinaryUrl);
    }
}