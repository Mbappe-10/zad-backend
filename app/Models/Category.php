<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'categories';
    protected $guarded = ['id'];
    protected $fillable = ['parent_id','name_ar','name_en','slug','image_path','sort_order','is_active'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean'];
    }
}
