<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReceipt extends Model
{
    public const TYPE_PLATFORM = 'platform';
    public const TYPE_QUICK = 'quick';
    public const TYPE_CASHIER = 'cashier';

    protected $guarded = [];

    protected $hidden = ['pdf_path', 'pdf_sha256'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'opened_at' => 'datetime',
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
}
