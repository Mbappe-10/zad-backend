<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderJourneyProof extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'uploaded_by' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'photo_size_bytes' => 'integer',
            'photo_purged_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by',
        );
    }

    public function getPhotoUrlAttribute(): ?string
    {
        $path = trim((string) $this->photo_path);

        if ($path === '') {
            return null;
        }

        if (
            str_starts_with($path, 'https://') ||
            str_starts_with($path, 'http://')
        ) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->url($path);
    }
}
