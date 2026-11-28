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

    /** A return / credit note (إشعار دائن), opened from its own link. */
    public function creditNote(string $token): Response
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('sale_returns'), 404);
        $return = \App\Models\SaleReturn::query()->with('receipt')->where('public_token', $token)->firstOrFail();
        $receipt = $return->receipt;
        abort_unless($receipt, 404);
        $tax = app(\App\Services\App\StoreTaxService::class);
        $doc = app(\App\Services\App\ZatcaService::class)->ready()
            ? \App\Models\ZatcaDocument::query()->where('sale_return_id', $return->id)->first() : null;
        $qr = $doc?->qr;
        if (! $qr && $receipt->seller_vat_number) {
            $fields = [1 => (string) ($receipt->seller_trade_name ?: $receipt->store_name), 2 => (string) $receipt->seller_vat_number,
                3 => $return->issued_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 4 => number_format((float) $return->total, 2, '.', ''),
                5 => number_format((float) $return->vat_amount, 2, '.', '')];
            $tlv = '';
            foreach ($fields as $tag => $value) {
                $value = mb_strcut($value, 0, 255, 'UTF-8');
                $tlv .= chr($tag).chr(strlen($value)).$value;
            }
            $qr = base64_encode($tlv);
        }

        return response()->view('receipts.credit', [
            'c' => app(\App\Services\App\SaleReturnService::class)->payload($return),
            'r' => $this->receipts->payload($receipt),
            'qrSvg' => $qr ? $tax->qrSvg($qr, 170) : null,
            'receiptUrl' => url('/r/'.$receipt->public_token),
        ])->header('X-Robots-Tag', 'noindex');
    }

    private function find(string $token): SaleReceipt
    {
        abort_unless($this->receipts->available() && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return SaleReceipt::query()->with('order:id,number')->where('public_token', $token)->firstOrFail();
    }
}
