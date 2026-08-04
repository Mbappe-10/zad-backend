<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'stores';

    protected $fillable = [
        'productive_family_id',
        'city_id',
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'logo_path',
        'cover_path',
        'status',
        'is_open',
        'rating',
        'rating_count',
        'working_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'rating' => 'decimal:2',
            'rating_count' => 'integer',
            'working_hours' => 'array',
        ];
    }

    public function productiveFamily(): BelongsTo
    {
        return $this->belongsTo(ProductiveFamily::class, 'productive_family_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}