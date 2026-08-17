<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverProfileFieldSetting extends Model
{
    protected $fillable = ['key', 'label_ar', 'label_en', 'field_type', 'vehicle_types', 'options', 'validation', 'is_required', 'is_active', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return ['vehicle_types' => 'array', 'options' => 'array', 'validation' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }
}