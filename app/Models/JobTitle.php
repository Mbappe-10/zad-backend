<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobTitle extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'code',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'level',
        'can_be_digital',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'can_be_digital' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function displayName(
        string $locale = 'ar',
    ): string {
        if ($locale === 'ar') {
            return $this->name_ar
                ?: $this->name_en
                ?: $this->code;
        }

        return $this->name_en
            ?: $this->name_ar
            ?: $this->code;
    }
}
