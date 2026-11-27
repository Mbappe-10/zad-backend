<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierRecipeItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['per_unit' => 'float'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CashierMaterial::class, 'material_id');
    }
}
