<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JourneyRetentionSetting extends Model
{
    protected $fillable = [
        'completed_retention_hours',
        'cancelled_retention_hours',
        'problem_retention_hours',
        'purge_batch_size',
        'automatic_purge_enabled',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'completed_retention_hours' => 'integer',
            'cancelled_retention_hours' => 'integer',
            'problem_retention_hours' => 'integer',
            'purge_batch_size' => 'integer',
            'automatic_purge_enabled' => 'boolean',
            'updated_by_user_id' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'completed_retention_hours' => 16,
            'cancelled_retention_hours' => 24,
            'problem_retention_hours' => 168,
            'purge_batch_size' => 50,
            'automatic_purge_enabled' => false,
        ]);
    }
}