<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SecurePayoutTransferProofService
{
    private const DELIVERY_TYPE = 'authenticated';

    public function upload(UploadedFile $file): array
    {
        $cloudinary = $this->cloudinary();
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw new RuntimeException('تعذر قراءة ملف إثبات التحويل.');
        }

        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $isPdf = $mimeType === 'application/pdf';
        $resourceType = $isPdf ? 'raw' : 'image';
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $extension = $isPdf ? 'pdf' : ($extension ?: 'jpg');

        $prefix = trim((string) config(
            'filesystems.disks.public.cloudinary_prefix',
            'zad-sync',
        ), '/');

        $folder = implode('/', array_filter([
            $prefix,
            'secure',
            'payout-transfer-proofs',
            now()->format('Y/m'),
        ]));

        $publicId = $folder.'/'.Str::uuid();

        if ($resourceType === 'raw') {
            $publicId .= '.'.$extension;
        }

        try {
            $response = $cloudinary->uploadApi()->upload($realPath, [
                'public_id' => $publicId,
                'resource_type' => $resourceType,
                'type' => self::DELIVERY_TYPE,
                'overwrite' => false,
                'discard_original_filename' => true,
                'tags' => ['zad-sync', 'payout-transfer-proof', 'sensitive'],
            ])->getArrayCopy();
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                'تعذر حفظ إثبات التحويل في التخزين المحمي. حاول مرة أخرى.',
                previous: $exception,
            );
        }

        $uploadedPublicId = trim((string) ($response['public_id'] ?? ''));

        if ($uploadedPublicId === '') {
            throw new RuntimeException('لم يرجع التخزين مرجعًا صالحًا لإثبات التحويل.');
        }

        return [
            'public_id' => $uploadedPublicId,
            'asset_id' => filled($response['asset_id'] ?? null)
                ? (string) $response['asset_id']
                : null,
            'resource_type' => (string) ($response['resource_type'] ?? $resourceType),
            'delivery_type' => (string) ($response['type'] ?? self::DELIVERY_TYPE),
            'format' => (string) ($response['format'] ?? $extension),
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime_type' => $mimeType,
            'size' => (int) ($response['bytes'] ?? $file->getSize() ?? 0),
            'sha256' => hash_file('sha256', $realPath),
        ];
    }

    public function temporaryDownloadUrl(
        string $publicId,
        string $format,
        string $resourceType,
        string $deliveryType = self::DELIVERY_TYPE,
        int $ttlSeconds = 300,
    ): string {
        return $this->cloudinary()->uploadApi()->privateDownloadUrl(
            $publicId,
            $format,
            [
                'resource_type' => $resourceType,
                'type' => $deliveryType,
                'attachment' => false,
                'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
            ],
        );
    }

    public function delete(
        string $publicId,
        string $resourceType,
        string $deliveryType = self::DELIVERY_TYPE,
    ): void {
        $this->cloudinary()->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
            'type' => $deliveryType,
            'invalidate' => true,
        ]);
    }

    private function cloudinary(): Cloudinary
    {
        $cloudinaryUrl = trim((string) config(
            'filesystems.disks.public.cloudinary_url',
        ));

        if ($cloudinaryUrl === '') {
            throw new RuntimeException('CLOUDINARY_URL غير مضبوط في خدمة الباك إند.');
        }

        return new Cloudinary($cloudinaryUrl);
    }
}