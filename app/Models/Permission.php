<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'module',
        'action',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'is_sensitive',
        'requires_approval',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'permission_role',
        )->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_permissions',
        )
            ->withPivot([
                'effect',
                'granted_by',
                'reason',
                'expires_at',
            ])
            ->withTimestamps();
    }

    public function displayName(
        string $locale = 'ar',
    ): string {
        return $locale === 'ar'
            ? $this->name_ar
            : $this->name_en;
    }
}
