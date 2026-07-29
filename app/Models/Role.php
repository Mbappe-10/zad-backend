<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'key',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'is_system',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'permission_role',
        )->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'role_user',
        )
            ->withPivot([
                'assigned_by',
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
