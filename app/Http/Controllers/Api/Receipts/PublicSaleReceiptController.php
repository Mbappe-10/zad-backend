<?php

namespace App\Http\Controllers\Api\Receipts;

use App\Http\Controllers\Controller;
use App\Models\SaleReceipt;
use App\Services\SaleReceiptService;
use Illuminate\Http\Response;

/** The page a customer opens from a receipt link or QR. The token is the only key. */
class PublicSaleReceiptController extends Controller
{
    public function __construct(private readonly SaleReceiptService $receipts) {}

    public function show(string $token): Response
    {
        $receipt = $this->find($token);
        if ($receipt->opened_at === null) {
            $receipt->forceFill(['opened_at' => now()])->save();
        }

        $thermal = app(\App\Services\App\ReceiptStyleService::class)->thermalFor($receipt);
        if ($thermal) {
            return response()->view('receipts.thermal', [
                'r' => $this->receipts->payload($receipt),
                't' => $thermal,
                'pdfUrl' => url('/r/'.$token.'/pdf'),
                'whatsapp' => $this->receipts->whatsappUrl($receipt),
            ])->header('X-Robots-Tag', 'noindex');
        }

        return response()->view('receipts.public', [
            'r' => $this->receipts->payload($receipt),
            'pdfUrl' => url('/r/'.$token.'/pdf'),
            'whatsapp' => $this->receipts->whatsappUrl($receipt),
            'appUrl' => config('services.zad.app_url', env('ZAD_APP_URL', 'https://zad-sync-sa.onrender.com')),
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function pdf(string $token): Response
    {
        $receipt = $this->find($token);

        return response($this->receipts->pdf($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->receipts->filename($receipt).'"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function find(string $token): SaleReceipt
    {
        abort_unless($this->receipts->available() && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return SaleReceipt::query()->with('order:id,number')->where('public_token', $token)->firstOrFail();
    }
}
