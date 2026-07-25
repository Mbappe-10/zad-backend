<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model { protected $guarded=[]; protected function casts(): array { return ['gross_amount'=>'decimal:2','provider_fee'=>'decimal:2','net_amount'=>'decimal:2','paid_at'=>'datetime','failed_at'=>'datetime','metadata'=>'array']; } public function order(){return $this->belongsTo(Order::class);} public function provider(){return $this->belongsTo(PaymentProvider::class,'provider_id');} }
