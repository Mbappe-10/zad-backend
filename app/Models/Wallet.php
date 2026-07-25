<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $table = 'wallets';
    protected $guarded = ['id'];
    protected $fillable = ['owner_type','owner_id','currency','available_balance','pending_balance','is_frozen'];

    protected function casts(): array
    {
        return ['available_balance'=>'decimal:2','pending_balance'=>'decimal:2','is_frozen'=>'boolean'];
    }
}
