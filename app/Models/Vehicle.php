<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Vehicle extends Model { use SoftDeletes; protected $guarded=['id']; protected function casts(): array { return ['max_distance_km'=>'decimal:2','base_fee'=>'decimal:2','per_km_fee'=>'decimal:2','requires_box'=>'boolean','is_active'=>'boolean']; } }
