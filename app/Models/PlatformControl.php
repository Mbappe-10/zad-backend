<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class PlatformControl extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'zad.platform-control';

    protected $fillable = [
        'section',
        'value',
        'description',
        'is_sensitive',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_sensitive' => 'boolean',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function cachedValues(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            static fn (): array => self::query()
                ->get(['section', 'value'])
                ->mapWithKeys(static fn (self $control): array => [
                    $control->section => is_array($control->value)
                        ? $control->value
                        : [],
                ])
                ->all(),
        );
    }

    public static function forgetCachedValues(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(static function (): void {
            self::forgetCachedValues();
        });

        static::deleted(static function (): void {
            self::forgetCachedValues();
        });
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by',
        );
    }
}
