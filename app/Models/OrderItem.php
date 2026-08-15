<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $guarded = ['id'];

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'preparation_mode',
        'quantity',
        'unit_price',
        'total',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'options' => 'array',
        ];
    }

    public function requiresLivePreparation(): bool
    {
        return $this->preparation_mode === Product::PREPARATION_MADE_TO_ORDER;
    }
}