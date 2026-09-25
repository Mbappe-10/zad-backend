<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaunchCreatorContractVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'contract_starts_at' => 'datetime',
            'contract_ends_at' => 'datetime',
            'commission_value' => 'decimal:4',
            'snapshot' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(
            LaunchCampaignCreator::class,
            'launch_campaign_creator_id'
        );
    }
}