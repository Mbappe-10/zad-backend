<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryZone extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'delivery_zones';
    protected $guarded = ['id'];
    protected $fillable = ['city_id','name_ar','name_en','polygon','extra_fee','is_active'];

    protected function casts(): array
    {
        return ['polygon'=>'array','extra_fee'=>'decimal:2','is_active'=>'boolean'];
    }
}
