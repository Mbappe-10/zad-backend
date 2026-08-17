<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DriverDocument extends Model
{
    protected $fillable = ['driver_id', 'type', 'path', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'metadata'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function driver(): BelongsTo { return $this->belongsTo(Driver::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? url(Storage::url($this->path)) : null;
    }
}