<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ready_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'photo_deleted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
