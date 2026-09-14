<?php

namespace App\Filesystems;

use Cloudinary\Cloudinary;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use RuntimeException;

class CloudinaryStorageAdapter implements FilesystemAdapter, ChecksumProvider
{
    public function __construct(
        private readonly Cloudinary $cloudinary,
        private readonly string $cloudName,
        private readonly string $prefix = 'zad-sync',
        private readonly bool $secure = true,
    ) {
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->asset($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = tmpfile();

        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary upload stream.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $contents */
    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw new RuntimeException('Cloudinary upload contents must be a stream resource.');
        }

        $resource = $this->resource($path);
        $metadata = stream_get_meta_data($contents);
        $source = $metadata['uri'] ?? null;
        $temporary = null;

        if (! is_string($source) || $source === '' || ! is_file($source)) {
            $temporary = tempnam(sys_get_temp_dir(), 'zad-cloudinary-');

            if ($temporary === false) {
                throw new RuntimeException('Unable to create a temporary Cloudinary upload file.');
            }

            $target = fopen($temporary, 'wb');

            if ($target === false) {
                @unlink($temporary);
                throw new RuntimeException('Unable to open the temporary Cloudinary upload file.');
            }

            stream_copy_to_stream($contents, $target);
            fclose($target);
            $source = $temporary;
        }

        try {
            $this->cloudinary->uploadApi()->upload($source, [
                'public_id' => $resource['public_id'],
                'resource_type' => $resource['resource_type'],
                'overwrite' => true,
                'invalidate' => true,
            ]);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    public function read(string $path): string
    {
        $contents = file_get_contents($this->publicUrl($path));

        if ($contents === false) {
            throw new RuntimeException("Unable to read Cloudinary object: {$path}");
        }

        return $contents;
    }

    /** @return resource */
    public function readStream(string $path)
    {
        $stream = fopen($this->publicUrl($path), 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open Cloudinary object stream: {$path}");
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        $resource = $this->resource($path);

        $this->cloudinary->uploadApi()->destroy($resource['public_id'], [
            'resource_type' => $resource['resource_type'],
            'invalidate' => true,
        ]);
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = $this->prefixed(trim($path, '/'));

        foreach (['image', 'video', 'raw'] as $resourceType) {
            $this->cloudinary->uploadApi()->deleteAssetsByPrefix($prefix, [
                'resource_type' => $resourceType,
                'invalidate' => true,
            ]);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Cloudinary folders are created automatically when an asset is uploaded.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if ($visibility !== 'public') {
            throw new RuntimeException('ZAD Sync Cloudinary storage supports public visibility only.');
        }
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $asset = $this->asset($path);
        $format = strtolower((string) ($asset['format'] ?? pathinfo($path, PATHINFO_EXTENSION)));

        return new FileAttributes($path, null, null, null, $this->mimeFromFormat($format));
    }

    public function lastModified(string $path): FileAttributes
    {
        $asset = $this->asset($path);
        $timestamp = strtotime((string) ($asset['created_at'] ?? '')) ?: null;

        return new FileAttributes($path, null, null, $timestamp);
    }

    public function fileSize(string $path): FileAttributes
    {
        $asset = $this->asset($path);

        return new FileAttributes($path, (int) ($asset['bytes'] ?? 0));
    }

    /** @return iterable<StorageAttributes> */
    public function listContents(string $path, bool $deep): iterable
    {
        if (false) {
            yield new DirectoryAttributes($path);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $destinationResource = $this->resource($destination);

        $this->cloudinary->uploadApi()->upload($this->publicUrl($source), [
            'public_id' => $destinationResource['public_id'],
            'resource_type' => $destinationResource['resource_type'],
            'overwrite' => true,
            'invalidate' => true,
        ]);
    }

    public function checksum(string $path, Config $config): string
    {
        $asset = $this->asset($path);

        return (string) ($asset['etag'] ?? md5($this->read($path)));
    }

    public function getUrl(string $path): string
    {
        return $this->publicUrl($path);
    }

    /** @return array<string, mixed> */
    private function asset(string $path): array
    {
        $resource = $this->resource($path);
        $response = $this->cloudinary->adminApi()->asset($resource['public_id'], [
            'resource_type' => $resource['resource_type'],
        ]);

        return $response->getArrayCopy();
    }

    private function publicUrl(string $path): string
    {
        $resource = $this->resource($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $publicId = implode('/', array_map('rawurlencode', explode('/', $resource['public_id'])));
        $suffix = $resource['resource_type'] === 'raw' || $extension === ''
            ? ''
            : '.'.rawurlencode($extension);
        $scheme = $this->secure ? 'https' : 'http';

        return sprintf(
            '%s://res.cloudinary.com/%s/%s/upload/%s%s',
            $scheme,
            rawurlencode($this->cloudName),
            $resource['resource_type'],
            $publicId,
            $suffix,
        );
    }

    /** @return array{public_id: string, resource_type: string} */
    private function resource(string $path): array
    {
        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
        $extension = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
        $resourceType = $this->resourceType($extension);

        $publicId = $resourceType === 'raw'
            ? $cleanPath
            : preg_replace('/\.[^.\/]+$/', '', $cleanPath);

        return [
            'public_id' => $this->prefixed((string) $publicId),
            'resource_type' => $resourceType,
        ];
    }

    private function prefixed(string $path): string
    {
        $prefix = trim($this->prefix, '/');
        $path = trim($path, '/');

        return $prefix === '' ? $path : $prefix.'/'.$path;
    }

    private function resourceType(string $extension): string
    {
        if (in_array($extension, ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv'], true)) {
            return 'video';
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'svg'], true)) {
            return 'image';
        }

        return 'raw';
    }

    private function mimeFromFormat(string $format): string
    {
        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
