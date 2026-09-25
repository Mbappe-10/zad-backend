<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaunchCampaign extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function creatorAssignments(): HasMany
    {
        return $this->hasMany(LaunchCampaignCreator::class, 'campaign_id');
    }

    public function familyAssignments(): HasMany
    {
        return $this->hasMany(LaunchCampaignFamily::class, 'campaign_id');
    }
}
