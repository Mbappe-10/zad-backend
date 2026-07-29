<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $table = 'wallet_transactions';

    protected $guarded = ['id'];

    protected $fillable = ['wallet_id', 'reference', 'type', 'amount', 'balance_after', 'status', 'related_type', 'related_id', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }
}
