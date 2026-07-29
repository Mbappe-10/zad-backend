<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryAssignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['distance_km' => 'decimal:2', 'score' => 'decimal:2', 'offered_at' => 'datetime', 'expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
