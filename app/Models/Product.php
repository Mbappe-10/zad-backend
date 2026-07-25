<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'products';
    protected $guarded = ['id'];
    protected $fillable = ['store_id','category_id','sku','name_ar','name_en','description_ar','description_en','price','compare_at_price','status','is_available','preparation_minutes','images','variants','ingredients'];

    protected function casts(): array
    {
        return ['price'=>'decimal:2','compare_at_price'=>'decimal:2','is_available'=>'boolean','images'=>'array','variants'=>'array','ingredients'=>'array'];
    }
}
