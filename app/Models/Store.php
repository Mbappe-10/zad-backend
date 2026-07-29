<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'stores';

    protected $guarded = ['id'];

    protected $fillable = ['productive_family_id', 'city_id', 'name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'logo_path', 'cover_path', 'status', 'is_open', 'rating', 'rating_count', 'working_hours'];

    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'rating' => 'decimal:2', 'working_hours' => 'array'];
    }
}
