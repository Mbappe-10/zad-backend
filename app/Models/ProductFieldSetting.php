<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFieldSetting extends Model
{
    protected $fillable = [
        'field_key',
        'label_ar',
        'label_en',
        'is_enabled',
        'is_required',
        'family_visible',
        'family_editable',
        'owner_only',
        'sort_order',
        'options',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_required' => 'boolean',
            'family_visible' => 'boolean',
            'family_editable' => 'boolean',
            'owner_only' => 'boolean',
            'sort_order' => 'integer',
            'options' => 'array',
        ];
    }
}