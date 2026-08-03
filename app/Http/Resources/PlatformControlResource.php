<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformControlResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'section' => $this->section,

            'value' => $this->value ?? [],

            'description' => $this->description,

            'isSensitive' => (bool) $this->is_sensitive,

            'updatedBy' => $this->whenLoaded(
                'updatedBy',
                fn () => $this->updatedBy
                    ? [
                        'id' => $this->updatedBy->id,
                        'name' => $this->updatedBy->name,
                        'email' => $this->updatedBy->email,
                    ]
                    : null,
            ),

            'createdAt' => $this->created_at?->toISOString(),

            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}