<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyStoreGrant extends Model
{
    protected $fillable = ['productive_family_id', 'plan_code', 'source', 'starts_at', 'ends_at', 'revoked_at', 'granted_by', 'revoked_by', 'reason', 'revocation_reason', 'revoked_by_role'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
