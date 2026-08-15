<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveBroadcastSetting extends Model
{
    protected $fillable = [
        'default_grace_minutes',
        'warning_before_end_minutes',
        'maximum_extension_minutes',
        'auto_end_enabled',
        'audio_enabled',
        'chat_enabled',
        'screen_share_enabled',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'default_grace_minutes' => 'integer',
            'warning_before_end_minutes' => 'integer',
            'maximum_extension_minutes' => 'integer',
            'auto_end_enabled' => 'boolean',
            'audio_enabled' => 'boolean',
            'chat_enabled' => 'boolean',
            'screen_share_enabled' => 'boolean',
            'updated_by_user_id' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'default_grace_minutes' => 5,
                'warning_before_end_minutes' => 3,
                'maximum_extension_minutes' => 15,
                'auto_end_enabled' => true,
                'audio_enabled' => false,
                'chat_enabled' => false,
                'screen_share_enabled' => false,
            ],
        );
    }
}