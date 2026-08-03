<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PlatformControlCollection extends ResourceCollection
{
    public $collects = PlatformControlResource::class;

    public function toArray(Request $request): array
    {
        return [
            'sections' => $this->collection,
        ];
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'count' => $this->collection->count(),
                'generatedAt' => now()->toISOString(),
            ],
        ];
    }
}