<?php

namespace App\Http\Controllers\Api\Receipts;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's receipt for one of their paid orders (same access rule as order tracking). */
class CustomerOrderReceiptController extends Controller
{
    public function __construct(private readonly SaleReceiptService $receipts) {}

    public function show(Request $request, Order $order): JsonResponse
    {
        $guest = trim((string) $request->header('X-Guest-Session', ''));
        $allowedByGuest = $guest !== '' && $order->guest_session_id === $guest
            && app(\App\Services\App\OrderGuestAccess::class)->allows($request, $order);
        $allowedByCustomer = $request->user() !== null && $order->customer_id !== null
            && $order->customer_id === $request->user()->appProfile?->customer_id;
        abort_unless($allowedByGuest || $allowedByCustomer, 403);

        $receipt = $this->receipts->syncOrder($order);
        abort_unless($receipt !== null, 404, 'لا يوجد إيصال لهذا الطلب بعد.');

        return response()->json(['data' => $this->receipts->payload($receipt->loadMissing('order:id,number')) + [
            'pdf_url' => url('/r/'.$receipt->public_token.'/pdf'),
            'whatsapp_url' => $this->receipts->whatsappUrl($receipt),
        ]]);
    }
}
