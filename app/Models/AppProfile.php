<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppProfile extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'productive_family_id',
        'driver_id',
        'roles',
        'active_mode',
    ];

    protected function casts(): array
    {
        return ['roles' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function productiveFamily(): BelongsTo
    {
        return $this->belongsTo(ProductiveFamily::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}