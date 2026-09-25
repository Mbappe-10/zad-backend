<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaunchCampaignFamily extends Model
{
    protected $guarded = [];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(LaunchCampaign::class, 'campaign_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductiveFamily::class, 'family_id');
    }
}
