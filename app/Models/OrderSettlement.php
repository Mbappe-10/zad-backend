<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSettlement extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RELEASED = 'released';
    public const STATUS_HELD = 'held';
    public const STATUS_REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'order_total' => 'decimal:2',
            'family_gross' => 'decimal:2',
            'family_commission' => 'decimal:2',
            'family_net' => 'decimal:2',
            'driver_gross' => 'decimal:2',
            'driver_commission' => 'decimal:2',
            'driver_net' => 'decimal:2',
            'platform_total' => 'decimal:2',
            'release_due_at' => 'datetime',
            'released_at' => 'datetime',
            'calculation_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function productiveFamily(): BelongsTo
    {
        return $this->belongsTo(ProductiveFamily::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function familyWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'family_wallet_transaction_id');
    }

    public function driverWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'driver_wallet_transaction_id');
    }
}
