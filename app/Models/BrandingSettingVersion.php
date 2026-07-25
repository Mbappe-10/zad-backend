<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandingSettingVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'branding_setting_id',
        'version_number',
        'action',
        'change_summary',
        'settings_snapshot',
        'created_by',
        'creator_name',
        'creator_email',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'settings_snapshot' => 'array',
            'version_number' => 'integer',
        ];
    }

    public function brandingSetting(): BelongsTo
    {
        return $this->belongsTo(
            BrandingSetting::class,
            'branding_setting_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}