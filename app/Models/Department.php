<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'parent_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'parent_id',
        );
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            Department::class,
            'parent_id',
        )->orderBy('sort_order');
    }

    public function jobTitles(): HasMany
    {
        return $this->hasMany(JobTitle::class);
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