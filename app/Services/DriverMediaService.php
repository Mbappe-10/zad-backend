<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use RuntimeException;

/** Sensitive driver photos are never written to the public filesystem. */
class DriverMediaService
{
    private const MARKER = 'cld-auth:';

    private function cloudinary(): Cloudinary
    {
        $url = trim((string) config('filesystems.disks.public.cloudinary_url', ''));
        if ($url === '') {
            throw new RuntimeException('Cloudinary has not been configured on the backend.');
        }
        return new Cloudinary($url);
    }

    public function isProtected(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, self::MARKER);
    }

    public function publicId(string $path): string
    {
        if (! $this->isProtected($path)) {
            throw new RuntimeException('Not a protected Cloudinary reference.');
        }
        $id = substr($path, strlen(self::MARKER));
        if (! preg_match('/^[a-zA-Z0-9_\/-]+$/', $id)
            || ! str_contains($id, '/secure/driver-documents/')) {
            throw new RuntimeException('Invalid driver media reference.');
        }
        return $id;
    }

    /** @return array{path:string, metadata:array} */
    public function upload(UploadedFile $file, string $type, string $scope): array
    {
        if (! $file->isValid()) throw new RuntimeException('Invalid uploaded image.');
        if (! preg_match('/^[a-z][a-z0-9_]{1,79}$/', $type)) {
            throw new RuntimeException('Invalid document type.');
        }
        $source = $file->getRealPath();
        if (! is_string($source) || ! is_file($source)) {
            throw new RuntimeException('Cannot read image contents.');
        }
        $info = @getimagesize($source);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp'], true)) {
            throw new RuntimeException('JPG, PNG or WebP image required.');
        }
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w < 300 || $h < 300 || $w * $h > 30000000) {
            throw new RuntimeException('Image dimensions are unsupported.');
        }
        $sensitiveText = in_array($type, [
            'identity_photo', 'driving_license', 'motorcycle_license', 'vehicle_registration'
        ], true);
        $max = $sensitiveText ? 2000 : ($type === 'face_photo' ? 1100 : 1600);
        $quality = $sensitiveText ? 87 : ($type === 'face_photo' ? 83 : 78);
        $image = new ImageManager(
            GdDriver::class,
            autoOrientation: true,
            decodeAnimation: false,
            backgroundColor: 'ffffff',
            strip: true,
        );
        $decoded = $image->decodePath($source);
        // Never crop IDs or vehicle documents: preserve all edges and text.
        $decoded->scaleDown(width: $max, height: $max);
        $encoded = (string) $decoded->encodeUsingFormat(Format::WEBP, quality: $quality, strip: true);
        if (strlen($encoded) < 200 || strlen($encoded) > 4 * 1024 * 1024) {
            throw new RuntimeException('Compressed image size out of bounds.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'zad-driver-');
        if ($tmp === false) throw new RuntimeException('Unable to allocate temporary image.');
        try {
            if (file_put_contents($tmp, $encoded) === false) throw new RuntimeException('Unable to encode image.');
            $prefix = trim((string) config('filesystems.disks.public.cloudinary_prefix', 'zad-sync'), '/');
            // Scope is generated server-side (current user/driver or administration), never a user path.
            $folder = $prefix.'/secure/driver-documents/'.$scope;
            $id = $folder.'/'.Str::uuid();
            $response = $this->cloudinary()->uploadApi()->upload($tmp, [
                'public_id' => $id,
                'resource_type' => 'image',
                'type' => 'authenticated',
                'format' => 'webp',
                'overwrite' => false,
                'discard_original_filename' => true,
                'tags' => ['zad-sync', 'driver-document', 'sensitive'],
            ])->getArrayCopy();
            $id = (string) ($response['public_id'] ?? '');
            if ($id === '') throw new RuntimeException('Cloudinary returned no public ID.');
            return [
                'path' => self::MARKER.$id,
                'metadata' => [
                    'storage' => 'cloudinary_authenticated',
                    'format' => (string) ($response['format'] ?? 'webp'),
                    'size_bytes' => (int) ($response['bytes'] ?? strlen($encoded)),
                    'resource_type' => 'image',
                    'delivery_type' => 'authenticated',
                ],
            ];
        } finally {
            @unlink($tmp);
        }
    }

    public function temporaryUrl(string $path, int $seconds = 180): ?string
    {
        if (! $this->isProtected($path)) return null;
        return $this->cloudinary()->uploadApi()->privateDownloadUrl(
            $this->publicId($path), 'webp', [
                'resource_type' => 'image',
                'type' => 'authenticated',
                'attachment' => false,
                'expires_at' => now()->addSeconds($seconds)->timestamp,
            ],
        );
    }

    public function delete(?string $path): void
    {
        if (! $this->isProtected($path)) return;
        $this->cloudinary()->uploadApi()->destroy($this->publicId($path), [
            'resource_type' => 'image', 'type' => 'authenticated', 'invalidate' => true,
        ]);
    }
}
