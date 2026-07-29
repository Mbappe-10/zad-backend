<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRuleRun extends Model
{
    protected $fillable = ['automation_rule_id', 'triggered_by', 'status', 'input', 'output', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
