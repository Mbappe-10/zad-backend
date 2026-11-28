<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['signed_xml'];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'errors' => 'array',
            'next_attempt_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** Signed XML is stored gzip-compressed (about a quarter of the size). */
    protected function signedXml(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => is_string($v) && str_starts_with($v, 'gz:') ? (string) gzdecode((string) base64_decode(substr($v, 3))) : $v,
            set: fn (?string $v) => $v === null ? null : 'gz:'.base64_encode((string) gzencode($v, 9)),
        );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ZatcaEgsUnit::class, 'egs_unit_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(SaleReceipt::class, 'sale_receipt_id');
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }
}
