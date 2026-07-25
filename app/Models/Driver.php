<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Driver extends Model
{
 use HasFactory,SoftDeletes;
 protected $guarded=['id'];
 protected $fillable=['user_id','city_id','vehicle_id','code','name','phone','identity_number','license_number','vehicle_type','plate_number','status','is_online','current_latitude','current_longitude','location_updated_at','active_orders_count','rating'];
 protected function casts():array{return ['is_online'=>'boolean','current_latitude'=>'decimal:7','current_longitude'=>'decimal:7','location_updated_at'=>'datetime','active_orders_count'=>'integer','rating'=>'decimal:2'];}
 public function vehicle(){return $this->belongsTo(Vehicle::class);}
}
