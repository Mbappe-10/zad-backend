<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'processing_at' => 'datetime',
            'executed_at' => 'datetime',
            'transfer_proof_uploaded_at' => 'datetime',
            'paid_at' => 'datetime',
            'contract_signed_at' => 'datetime',
            'declaration_signed_at' => 'datetime',
            'iban_proof_required' => 'boolean',
            'iban_proof_uploaded_at' => 'datetime',
            'iban_proof_deleted_at' => 'datetime',
            'policy_snapshot' => 'array',
            'declaration_snapshot' => 'array',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
