<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SecurePayoutProofService
{
    private const DELIVERY_TYPE = 'authenticated';

    /**
     * @return array{
     *   public_id: string,
     *   asset_id: ?string,
     *   resource_type: string,
     *   delivery_type: string,
     *   format: string,
     *   original_name: string,
     *   mime_type: string,
     *   size: int,
     *   sha256: string
     * }
     */
    public function upload(UploadedFile $file): array
    {
        $cloudinary = $this->cloudinary();
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw new RuntimeException('تعذر قراءة ملف إثبات الآيبان.');
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
            'payout-iban-proofs',
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
                'tags' => ['zad-sync', 'payout-iban-proof', 'sensitive'],
            ])->getArrayCopy();
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                'تعذر حفظ إثبات الآيبان في التخزين المحمي. حاول مرة أخرى.',
                previous: $exception,
            );
        }

        $uploadedPublicId = trim((string) ($response['public_id'] ?? ''));

        if ($uploadedPublicId === '') {
            throw new RuntimeException('لم يرجع التخزين مرجعًا صالحًا لإثبات الآيبان.');
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
