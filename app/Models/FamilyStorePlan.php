<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyStorePlan extends Model
{
    protected $fillable = ['name', 'description', 'price', 'duration_days', 'features', 'capabilities', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'features' => 'array',
            'capabilities' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }
}
