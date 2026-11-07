<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SponsoredCreatorCoupon extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_REVOKED = 'revoked';

    protected $table = 'sponsored_creator_coupons';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'allowed_products' => 'array',
            'gross_cap' => 'decimal:2',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'consumed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(LaunchCreator::class, 'creator_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consumedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'consumed_order_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->activated_at !== null
            && $this->activated_at->lessThanOrEqualTo(now())
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && $this->archived_at === null
            && $this->revoked_at === null
            && $this->consumed_at === null
            && $this->consumed_order_id === null;
    }
}