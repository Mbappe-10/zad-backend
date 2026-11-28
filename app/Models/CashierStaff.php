<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierStaff extends Model
{
    protected $table = 'cashier_staff';

    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'active' => 'boolean',
            'approval_required' => 'boolean',
            'removed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
