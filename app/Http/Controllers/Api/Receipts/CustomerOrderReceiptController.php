<?php

namespace App\Http\Controllers\Api\Receipts;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\SaleReceipt;

/** The customer's receipt for one of their paid orders (same access rule as order tracking). */
class CustomerOrderReceiptController extends Controller
{
    public function __construct(private readonly SaleReceiptService $receipts) {}

    public function show(Request $request, Order $order): JsonResponse
    {
        $receipt = $this->receiptFor($request, $order);

        return response()->json(['data' => $this->receipts->payload($receipt->loadMissing('order:id,number')) + [
            'pdf_url' => url('/r/'.$receipt->public_token.'/pdf'),
            'whatsapp_url' => $this->receipts->whatsappUrl($receipt),
        ]]);
    }

    public function pdf(Request $request, Order $order): Response
    {
        $receipt = $this->receiptFor($request, $order);

        return response($this->receipts->pdf($receipt->loadMissing('order:id,number')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->receipts->filename($receipt).'"',
        ]);
    }

    private function receiptFor(Request $request, Order $order): SaleReceipt
    {
        $guest = trim((string) $request->header('X-Guest-Session', ''));
        $allowedByGuest = $guest !== '' && $order->guest_session_id === $guest
            && app(\App\Services\App\OrderGuestAccess::class)->allows($request, $order);
        $allowedByCustomer = $request->user() !== null && $order->customer_id !== null
            && $order->customer_id === $request->user()->appProfile?->customer_id;
        abort_unless($allowedByGuest || $allowedByCustomer, 403);

        $receipt = $this->receipts->syncOrder($order);
        abort_unless($receipt !== null, 404, 'لا يوجد إيصال لهذا الطلب بعد.');

        return $receipt;
    }
}
