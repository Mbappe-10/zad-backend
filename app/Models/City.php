<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class City extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'cities';
    protected $guarded = ['id'];
    protected $fillable = ['code','name_ar','name_en','is_active','delivery_base_fee','manager_id'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean','delivery_base_fee'=>'decimal:2'];
    }
}
