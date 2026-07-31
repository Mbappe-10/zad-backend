<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Order extends Model {
 use HasFactory,SoftDeletes; protected $guarded=['id'];
 protected $fillable=['number','customer_id','guest_session_id','contact_phone','store_id','driver_id','city_id','delivery_zone_id','status','payment_status','subtotal','delivery_fee','discount','tax','total','delivery_address','delivery_distance_km','delivery_latitude','delivery_longitude','notes','package_size','recommended_vehicle_type','assigned_vehicle_type','vehicle_rule_overridden','vehicle_rule_overridden_by','vehicle_override_reason','accepted_at','preparing_at','ready_at','picked_up_at','delivered_at','cancelled_at'];
 protected function casts():array{return ['subtotal'=>'decimal:2','delivery_fee'=>'decimal:2','discount'=>'decimal:2','tax'=>'decimal:2','total'=>'decimal:2','delivery_distance_km'=>'decimal:2','delivery_latitude'=>'decimal:7','delivery_longitude'=>'decimal:7','delivery_address'=>'array','vehicle_rule_overridden'=>'boolean','accepted_at'=>'datetime','preparing_at'=>'datetime','ready_at'=>'datetime','picked_up_at'=>'datetime','delivered_at'=>'datetime','cancelled_at'=>'datetime'];}
 public function driver(){return $this->belongsTo(Driver::class);} public function store(){return $this->belongsTo(Store::class);} public function customer(){return $this->belongsTo(Customer::class);} public function items(){return $this->hasMany(OrderItem::class);} public function assignments(){return $this->hasMany(DeliveryAssignment::class);} public function history(){return $this->hasMany(OrderStatusHistory::class);} public function journeyProofs(){return $this->hasMany(OrderJourneyProof::class);} }
