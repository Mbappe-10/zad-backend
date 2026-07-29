<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeProfile extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'department_id', 'job_title_id', 'employee_number', 'name_ar', 'name_en', 'email', 'phone', 'employment_type', 'status', 'hire_date', 'monthly_cost', 'skills', 'kpis', 'notes'];

    protected function casts(): array
    {
        return ['hire_date' => 'date', 'monthly_cost' => 'decimal:2', 'skills' => 'array', 'kpis' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }
}
