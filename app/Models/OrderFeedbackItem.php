<?php

namespace App\Models;

// ZAD_FINAL_DELIVERY_V2
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFeedbackItem extends Model
{
    protected $fillable = [
        'order_id',
        'order_rating_id',
        'subject_type',
        'subject_id',
        'category',
        'details',
        'status',
        'visible_to_subject',
        'acknowledged_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'visible_to_subject' => 'boolean',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(OrderRating::class, 'order_rating_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
