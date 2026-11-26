<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyStorePurchase extends Model
{
    protected $fillable = [
        'productive_family_id', 'plan_code', 'amount_halalas', 'duration_days',
        'currency', 'mode', 'status', 'invoice_id', 'checkout_url',
        'callback_token_hash', 'provider_payment_id', 'expires_at',
        'paid_at', 'grant_id', 'created_by', 'given_id', 'receipt_number',
        'receipt_snapshot', 'receipt_hash', 'receipt_pdf_path', 'receipt_pdf_sha256', 'last_checked_at',
        'web_intent_id', 'web_intent_state', 'web_client_secret_enc',
    ];

    protected $hidden = ['callback_token_hash', 'web_client_secret_enc'];

    protected function casts(): array
    {
        return [
            'amount_halalas' => 'integer',
            'duration_days' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'receipt_snapshot' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }
}
