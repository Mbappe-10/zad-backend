<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderStatusHistory extends Model { protected $guarded=['id']; protected function casts(): array { return ['metadata'=>'array']; } public function changedBy(){return $this->belongsTo(User::class,'changed_by');} public function order(){return $this->belongsTo(Order::class);} }
