<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'delivery_at' => 'datetime',
            'decided_at' => 'datetime',
            'guest_count' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}