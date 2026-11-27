<?php

namespace App\Http\Controllers\Api\Receipts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class SaleReceiptFilters
{
    /** Shared filters: q (receipt/order number or phone digits), from/to (Riyadh dates), store, channel, type, method, status. */
    public static function apply(Builder $query, array $f): Builder
    {
        $tz = 'Asia/Riyadh';
        if (! empty($f['from'])) {
            $query->where('issued_at', '>=', Carbon::parse($f['from'], $tz)->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $query->where('issued_at', '<=', Carbon::parse($f['to'], $tz)->endOfDay()->utc());
        }
        foreach (['store_id' => 'store_id', 'channel' => 'channel', 'sale_type' => 'sale_type', 'payment_method' => 'payment_method', 'status' => 'status'] as $key => $column) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $query->where($column, $f[$key]);
            }
        }
        $staff = trim((string) ($f['staff'] ?? ''));
        if ($staff !== '') {
            $query->where('staff_name', 'like', '%'.$staff.'%');
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $query->where(function (Builder $w) use ($q, $digits): void {
                $w->where('number', 'like', '%'.strtoupper($q).'%')
                    ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', '%'.$q.'%'));
                if (strlen($digits) >= 4) {
                    $w->orWhere('customer_phone', 'like', '%'.substr($digits, -9).'%');
                }
            });
        }

        return $query;
    }
}
