<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyVersion extends Model
{
    protected $fillable = [
        'policy_document_id',
        'version',
        'title_ar',
        'title_en',
        'content_ar',
        'content_en',
        'status',
        'requires_acceptance',
        'change_summary',
        'effective_at',
        'published_at',
        'created_by',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_acceptance' => 'boolean',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class, 'policy_document_id');
    }
}