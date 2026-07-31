<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PhoneVerification extends Model { protected $guarded=[]; protected function casts(): array { return ['expires_at'=>'datetime','verified_at'=>'datetime']; } }
