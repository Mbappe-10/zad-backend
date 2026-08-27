<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderMessage extends Model
{
    public const ROLE_DRIVER = 'driver';
    public const ROLE_FAMILY = 'family';

    protected $fillable = [
        'order_id',
        'sender_user_id',
        'sender_role',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'sender_user_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'sender_user_id',
        );
    }
}