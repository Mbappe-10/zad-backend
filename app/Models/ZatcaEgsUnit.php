<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A store's e-invoice generation unit registered with ZATCA (Fatoora). Keys and secrets are encrypted. */
class ZatcaEgsUnit extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['private_key', 'csr', 'compliance_certificate', 'compliance_secret', 'certificate', 'secret'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'compliance_secret' => 'encrypted',
            'secret' => 'encrypted',
            'certificate_expires_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->certificate && $this->secret;
    }
}
