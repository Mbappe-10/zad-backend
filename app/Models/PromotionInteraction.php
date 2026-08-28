<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionInteraction extends Model
{
    protected $fillable = [
        'promotion_id',
        'event_type',
        'event_key',
        'session_hash',
        'attribution_token_hash',
        'attributed_order_id',
        'city_id',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'attributed_order_id' => 'integer',
            'city_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
