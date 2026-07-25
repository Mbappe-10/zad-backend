<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Refund extends Model { protected $guarded=[]; protected function casts(): array { return ['amount'=>'decimal:2','approved_at'=>'datetime','refunded_at'=>'datetime','metadata'=>'array']; } public function payment(){return $this->belongsTo(Payment::class);} }
