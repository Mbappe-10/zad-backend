<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payout extends Model { protected $guarded=[]; protected function casts(): array { return ['amount'=>'decimal:2','fee'=>'decimal:2','net_amount'=>'decimal:2','approved_at'=>'datetime','paid_at'=>'datetime']; } public function wallet(){return $this->belongsTo(Wallet::class);} }
