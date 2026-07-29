<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $guarded = ['id'];

    protected $fillable = ['order_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'total', 'options'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'total' => 'decimal:2', 'options' => 'array'];
    }
}
