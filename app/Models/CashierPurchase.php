<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierPurchase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'subtotal' => 'float', 'vat' => 'float', 'total' => 'float', 'paid' => 'float',
            'due_date' => 'date', 'received_at' => 'datetime'];
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashierPurchaseItem::class, 'purchase_id');
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CashierSupplier::class, 'supplier_id');
    }
}
