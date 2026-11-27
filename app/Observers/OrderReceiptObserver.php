<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\SaleReceiptService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Issues the sale receipt once an order is paid; only reads the order, never changes it. */
class OrderReceiptObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Order $order): void
    {
        if ($order->wasChanged(['payment_status', 'status']) || $order->wasRecentlyCreated) {
            app(SaleReceiptService::class)->syncOrder($order);
        }
    }
}
