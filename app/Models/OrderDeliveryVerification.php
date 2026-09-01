<?php

namespace App\Models;

// ZAD_DELIVERY_OTP_V1
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDeliveryVerification extends Model
{
    protected $fillable = [
        'order_id',
        'code',
        'code_hash',
        'failed_attempts',
        'expires_at',
        'verified_at',
        'verified_by_driver_id',
    ];

    protected $hidden = ['code', 'code_hash'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'failed_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'verified_by_driver_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function verifiedByDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'verified_by_driver_id');
    }
}