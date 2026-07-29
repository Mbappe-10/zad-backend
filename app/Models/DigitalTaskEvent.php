<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalTaskEvent extends Model
{
    protected $fillable = ['digital_employee_task_id', 'actor_id', 'event_type', 'from_status', 'to_status', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(DigitalEmployeeTask::class, 'digital_employee_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
