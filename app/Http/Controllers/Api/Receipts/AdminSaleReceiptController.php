<?php

namespace App\Http\Controllers\Api\Receipts;

use App\Http\Controllers\Controller;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Services\SaleReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Governance: every sale receipt on the platform, searchable and exportable. */
class AdminSaleReceiptController extends Controller
{
    private const TZ = 'Asia/Riyadh';
    private const MAX_EXPORT = 5000;
    private const MAX_ZIP = 300;

    public function __construct(private readonly SaleReceiptService $receipts) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->receipts->available()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0], 'summary' => null, 'stores' => []]);
        }
        $this->receipts->syncMissing(100);
        $filters = $this->filters($request);
        $page = $this->query($filters)->paginate((int) ($filters['per_page'] ?? 30));
        $filtered = $this->query($filters)->reorder();

        return response()->json([
            'data' => collect($page->items())->map(fn (SaleReceipt $r) => $this->receipts->payload($r, true))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'summary' => $this->summary() + [
                'filtered' => ['count' => (clone $filtered)->count(), 'total' => round((float) (clone $filtered)->where('status', 'paid')->sum('total'), 2)],
            ],
            'stores' => Store::query()->whereIn('id', SaleReceipt::query()->select('store_id')->distinct())
                ->orderBy('name_ar')->get(['id', 'name_ar'])->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name_ar])->values(),
        ]);
    }

    public function show(SaleReceipt $receipt): JsonResponse
    {
        $receipt->loadMissing('order:id,number');

        return response()->json(['data' => $this->receipts->payload($receipt, true) + [
            'events' => array_values(array_filter([
                ['at' => $receipt->issued_at?->toIso8601String(), 'label' => 'صدر الإيصال'],
                $receipt->opened_at ? ['at' => $receipt->opened_at->toIso8601String(), 'label' => 'فتح العميل الإيصال'] : null,
            ])),
        ]]);
    }

    public function pdf(SaleReceipt $receipt): Response
    {
        return response($this->receipts->pdf($receipt->loadMissing('order:id,number')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->receipts->filename($receipt).'"',
        ]);
    }

    /** All receipts matching the filters as one zip of PDFs. */
    public function zip(Request $request): Response
    {
        $receipts = $this->query($this->filters($request))->with('order:id,number')->limit(self::MAX_ZIP + 1)->get();
        abort_if($receipts->isEmpty(), 422, 'لا توجد إيصالات في هذه الفترة.');
        abort_if($receipts->count() > self::MAX_ZIP, 422, 'الحد الأقصى '.self::MAX_ZIP.' إيصال في الملف الواحد، ضيّق الفترة.');
        $dir = storage_path('app/private/sale-receipt-zips');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.uniqid('receipts-', true).'.zip';
        $zip = new \ZipArchive();
        abort_unless($zip->open($path, \ZipArchive::CREATE) === true, 500);
        foreach ($receipts as $receipt) {
            $zip->addFromString($this->receipts->filename($receipt), $this->receipts->pdf($receipt));
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return response($bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="zad-receipts-'.now(self::TZ)->format('Y-m-d').'.zip"',
        ]);
    }

    /** Sales report of the filtered receipts: xlsx, pdf, doc (Word) or csv. */
    public function export(Request $request): Response
    {
        $format = (string) $request->validate(['format' => ['required', 'in:xlsx,pdf,doc,csv']])['format'];
        $receipts = $this->query($this->filters($request))->with('order:id,number')->limit(self::MAX_EXPORT)->get();
        $rows = $receipts->map(fn (SaleReceipt $r): array => $this->row($r))->all();
        $paid = $receipts->where('status', 'paid');
        $totals = ['count' => $receipts->count(), 'total' => round((float) $paid->sum('total'), 2)];
        $name = 'zad-sales-'.now(self::TZ)->format('Y-m-d');

        return match ($format) {
            'xlsx' => $this->xlsx($rows, $totals, $name),
            'csv' => $this->csv($rows, $totals, $name),
            'pdf' => $this->reportPdf($rows, $totals, $name, $request),
            'doc' => response($this->reportHtml($rows, $totals, $request, true), 200, [
                'Content-Type' => 'application/msword; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$name.'.doc"',
            ]),
        };
    }

    private const HEADERS = ['رقم الإيصال', 'رقم الطلب', 'التاريخ', 'الأسرة', 'نوع البيع', 'القناة', 'الدفع', 'العميل', 'الأصناف', 'الخصم', 'الإجمالي', 'الحالة'];

    private function row(SaleReceipt $r): array
    {
        $p = $this->receipts->payload($r);

        return [
            $p['number'], $p['order_number'] ?? '', $r->issued_at?->timezone(self::TZ)->format('Y-m-d H:i'),
            $p['store_name'] ?? '', $p['sale_type_label'], $p['channel_label'], $p['payment_method_label'], $p['customer'] ?? '',
            collect($p['items'])->map(fn ($i) => $i['quantity'].'× '.$i['name'])->implode('، '),
            $p['discount'], $p['total'], $p['status_label'],
        ];
    }

    private function xlsx(array $rows, array $totals, string $name): Response
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(true)->setTitle('المبيعات');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $last = count($rows) + 2;
        $sheet->setCellValue('A'.$last, 'الإجمالي ('.$totals['count'].' إيصال)');
        $sheet->setCellValue('K'.$last, $totals['total']);
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        $sheet->getStyle('A'.$last.':L'.$last)->getFont()->setBold(true);
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        $bytes = (string) ob_get_clean();

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
        ]);
    }

    private function csv(array $rows, array $totals, string $name): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fputcsv($out, ['الإجمالي ('.$totals['count'].' إيصال)', '', '', '', '', '', '', '', '', '', $totals['total'], '']);
        rewind($out);

        return response((string) stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
        ]);
    }

    private function reportPdf(array $rows, array $totals, string $name, Request $request): Response
    {
        $temp = storage_path('app/private/sale-receipt-mpdf');
        File::ensureDirectoryExists($temp);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $temp, 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $pdf->WriteHTML($this->reportHtml($rows, $totals, $request, false));

        return response($pdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
        ]);
    }

    private function reportHtml(array $rows, array $totals, Request $request, bool $word): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $period = trim(($request->input('from') ?: '').' — '.($request->input('to') ?: ''), ' —');
        $head = implode('', array_map(fn ($h) => '<th>'.$e($h).'</th>', self::HEADERS));
        $body = implode('', array_map(fn ($r) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.$e($c).'</td>', $r)).'</tr>', $rows));

        return '<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>'
            .'body{font-family:'.($word ? 'Arial' : 'sans-serif').';color:#102B4E;font-size:10pt}'
            .'table{width:100%;border-collapse:collapse}th{background:#102B4E;color:#fff;padding:6px;font-size:9pt}'
            .'td{border-bottom:1px solid #E6E9EE;padding:5px;font-size:9pt}h1{color:#FF7900;font-size:16pt;margin:0}'
            .'</style></head><body dir="rtl"><h1>تقرير مبيعات زاد سينك</h1>'
            .'<p>'.($period !== '' ? 'الفترة: '.$e($period).' — ' : '').'عدد الإيصالات: '.$totals['count'].' — إجمالي المدفوع: '.number_format($totals['total'], 2).' ر.س</p>'
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>'
            .'<p style="color:#788292;font-size:8pt">إيصالات بيع غير ضريبية صادرة عبر منصة زاد سينك</p></body></html>';
    }

    private function summary(): array
    {
        $now = now(self::TZ);
        $range = function (Carbon $from, Carbon $to): array {
            $q = SaleReceipt::query()->where('status', 'paid')->whereBetween('issued_at', [$from->copy()->utc(), $to->copy()->utc()]);

            return [
                'count' => (clone $q)->count(),
                'total' => round((float) (clone $q)->sum('total'), 2),
                'online' => round((float) (clone $q)->where('sale_type', SaleReceipt::TYPE_PLATFORM)->sum('total'), 2),
                'direct' => round((float) (clone $q)->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM)->sum('total'), 2),
            ];
        };

        return [
            'today' => $range($now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'yesterday' => $range($now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()),
            'month' => $range($now->copy()->startOfMonth(), $now->copy()->endOfDay()),
        ];
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'max:40'],
            'sale_type' => ['nullable', 'in:platform,quick,cashier'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:paid,refunded,cancelled'],
            'per_page' => ['nullable', 'integer', 'between:10,200'],
        ]);
    }

    private function query(array $filters): Builder
    {
        return SaleReceiptFilters::apply(SaleReceipt::query()->with('order:id,number')->orderByDesc('issued_at')->orderByDesc('id'), $filters);
    }
}
