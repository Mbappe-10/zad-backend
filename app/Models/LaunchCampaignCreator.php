<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaunchCampaignCreator extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'commission_value' => 'decimal:4',
            'contract_starts_at' => 'datetime',
            'contract_ends_at' => 'datetime',
            'contract_cancelled_at' => 'datetime',
            'contract_updated_at' => 'datetime',
            'contract_snapshot' => 'array',
        ];
    }

    public function contractIsActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->contract_status !== 'active') {
            return false;
        }

        if (! $this->contract_starts_at || ! $this->contract_ends_at) {
            return false;
        }

        $now = now();

        if ($now->lt($this->contract_starts_at)) {
            return false;
        }

        if ($now->gt($this->contract_ends_at)) {
            return false;
        }

        if ($this->contract_cancelled_at !== null) {
            return false;
        }

        return true;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(LaunchCampaign::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(LaunchCreator::class, 'creator_id');
    }
}
