<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'is_public',
        'description',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_public' => 'boolean',
        ];
    }

    public static function isEnabled(
        string $key,
        bool $default = false,
    ): bool {
        $setting = self::query()
            ->where('key', $key)
            ->first(['value']);

        if ($setting === null) {
            return $default;
        }

        return filter_var(
            $setting->value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
    }

    protected static function booted(): void
    {
        static::saved(static function (): void {
            Cache::forget('app.bootstrap.v2');
        });

        static::deleted(static function (): void {
            Cache::forget('app.bootstrap.v2');
        });
    }
}
