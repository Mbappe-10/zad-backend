<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppProfile extends Model
{
    protected $fillable = ['user_id','customer_id','productive_family_id','driver_id','roles','active_mode'];
    protected function casts(): array { return ['roles'=>'array']; }
    public function user(){ return $this->belongsTo(User::class); }
    public function customer(){ return $this->belongsTo(Customer::class); }
    public function productiveFamily(){ return $this->belongsTo(ProductiveFamily::class); }
    public function driver(){ return $this->belongsTo(Driver::class); }
}
