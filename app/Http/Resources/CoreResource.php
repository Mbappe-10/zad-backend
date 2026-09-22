<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($this->resource instanceof Product) {
            $images = $this->normalizeProductImages(
                $data['images'] ?? null,
            );

            $data['images'] = $images;
            $data['image_url'] = $images[0] ?? null;
            $data['primary_image_url'] = $images[0] ?? null;
        }

        return $data;
    }

    /** @return list<string> */
    private function normalizeProductImages(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(
                fn (mixed $image): bool =>
                    is_string($image) && trim($image) !== '',
            )
            ->map(
                fn (string $image): string =>
                    $this->productImageUrl($image),
            )
            ->filter()
            ->values()
            ->all();
    }

    private function productImageUrl(string $path): string
    {
        $cleanPath = trim($path);

        if (
            str_starts_with($cleanPath, 'http://') ||
            str_starts_with($cleanPath, 'https://')
        ) {
            return $cleanPath;
        }

        $cleanPath = ltrim($cleanPath, '/');

        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr(
                $cleanPath,
                strlen('storage/'),
            );
        }

        return Storage::disk('public')->url($cleanPath);
    }
}
