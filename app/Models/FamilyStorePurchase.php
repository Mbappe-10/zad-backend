<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyStorePurchase extends Model
{
    protected $fillable = [
        'productive_family_id', 'plan_code', 'amount_halalas', 'duration_days',
        'currency', 'mode', 'status', 'invoice_id', 'checkout_url',
        'callback_token_hash', 'provider_payment_id', 'expires_at',
        'paid_at', 'grant_id', 'created_by',
    ];

    protected $hidden = ['callback_token_hash'];

    protected function casts(): array
    {
        return [
            'amount_halalas' => 'integer',
            'duration_days' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
