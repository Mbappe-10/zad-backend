<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const PREPARATION_READY_STOCK = 'ready_stock';
    public const PREPARATION_MADE_TO_ORDER = 'made_to_order';

    protected $table = 'products';

    protected $guarded = ['id'];

    protected $fillable = [
        'store_id',
        'category_id',
        'sku',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'price',
        'compare_at_price',
        'status',
        'is_available',
        'preparation_minutes',
        'preparation_mode',
        'package_size',
        'images',
        'variants',
        'ingredients',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_available' => 'boolean',
            'cashier_only' => 'boolean',
            'track_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'stock_auto_closed' => 'boolean',
            'preparation_minutes' => 'integer',
            'images' => 'array',
            'variants' => 'array',
            'ingredients' => 'array',
        ];
    }

    private static ?bool $hasCashierColumns = null;

    public static function hasCashierColumns(): bool
    {
        return self::$hasCashierColumns ??= \Illuminate\Support\Facades\Schema::hasColumn('products', 'cashier_only');
    }

    /** Products customers can see and order online (cashier-only products stay in the family's cashier). */
    public function scopeOnlineVisible(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return self::hasCashierColumns() ? $query->where($query->qualifyColumn('cashier_only'), false) : $query;
    }

    public static function preparationModes(): array
    {
        return [
            self::PREPARATION_READY_STOCK,
            self::PREPARATION_MADE_TO_ORDER,
        ];
    }

    public function requiresLivePreparation(): bool
    {
        return $this->preparation_mode === self::PREPARATION_MADE_TO_ORDER;
    }
}