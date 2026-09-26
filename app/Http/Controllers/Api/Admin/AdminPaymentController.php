<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandingSetting;
use App\Models\FinancialLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Services\AdminPaymentRegisterService;
use App\Services\FinancialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly AdminPaymentRegisterService $register,
        private readonly FinancialService $financialService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request, pagination: true);
        $result = $this->register->paginate(
            $filters,
            (int) ($filters['per_page'] ?? 15),
        );

        return response()->json([
            ...$result['paginator']->toArray(),
            'summary' => $result['summary'],
        ]);
    }

    public function gateways(): JsonResponse
    {
        $gateways = PaymentProvider::query()
            ->withCount('payments')
            ->withSum([
                'payments as paid_total_amount' => fn ($query) =>
                    $query->where('status', 'paid'),
            ], 'gross_amount')
            ->orderBy('name')
            ->get()
            ->map(function (PaymentProvider $provider): array {
                $settings = is_array($provider->settings) ? $provider->settings : [];
                $status = (string) ($settings['status'] ?? (
                    $provider->is_active ? 'active' : 'inactive'
                ));

                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'code' => $provider->code,
                    'status' => in_array($status, ['active', 'inactive', 'maintenance'], true)
                        ? $status
                        : 'inactive',
                    'fixed_fee' => (float) $provider->fixed_fee,
                    'percentage_fee' => (float) $provider->percentage_fee,
                    'supported_methods' => array_values((array) ($settings['supported_methods'] ?? [])),
                    'transactions_count' => (int) $provider->payments_count,
                    'total_amount' => (float) ($provider->paid_total_amount ?? 0),
                    'updated_at' => $provider->updated_at?->toIso8601String(),
                ];
            });

        return response()->json(['data' => $gateways]);
    }

    public function updateGatewayStatus(
        Request $request,
        PaymentProvider $provider,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive', 'maintenance'])],
        ]);
        $settings = is_array($provider->settings) ? $provider->settings : [];
        $settings['status'] = $data['status'];

        $provider->forceFill([
            'is_active' => $data['status'] === 'active',
            'settings' => $settings,
        ])->save();

        return response()->json([
            'message' => 'تم تحديث حالة بوابة الدفع.',
            'data' => [
                'id' => $provider->id,
                'status' => $data['status'],
            ],
        ]);
    }

    public function refund(
        Request $request,
        Payment $payment,
    ): JsonResponse {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $refund = $this->financialService->refund(
            $payment,
            $data,
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'تم تنفيذ الاسترداد وتسجيله في سجل المدفوعات.',
            'data' => $refund,
        ], 201);
    }

    public function storeManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['inbound', 'outbound'])],
            'category' => [
                'required',
                Rule::in([
                    'operating_expense',
                    'provider_payment',
                    'government_fee',
                    'staff_payment',
                    'other_payment',
                    'other_receipt',
                ]),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['required', Rule::in(['pending', 'paid', 'cancelled'])],
            'counterparty_name' => ['required', 'string', 'max:255'],
            'counterparty_email' => ['nullable', 'email', 'max:255'],
            'payment_method' => ['required', 'string', 'max:80'],
            'external_reference' => ['nullable', 'string', 'max:255', 'unique:financial_ledger_entries,external_reference'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'occurred_at' => ['required', 'date'],
        ]);

        if (
            ($data['direction'] === 'inbound' && $data['category'] !== 'other_receipt')
            || ($data['direction'] === 'outbound' && $data['category'] === 'other_receipt')
        ) {
            throw ValidationException::withMessages([
                'category' => ['نوع الحركة لا يتوافق مع اتجاهها المالي.'],
            ]);
        }

        $entry = FinancialLedgerEntry::query()->create([
            'entry_number' => 'LED-'.Str::upper(Str::random(16)),
            'entry_date' => date('Y-m-d', strtotime($data['occurred_at'])),
            'account_code' => $data['category'],
            'direction' => $data['direction'] === 'inbound' ? 'credit' : 'debit',
            'status' => $data['status'] === 'paid' ? 'completed' : $data['status'],
            'amount' => round((float) $data['amount'], 2),
            'currency' => Str::upper($data['currency'] ?? 'SAR'),
            'counterparty_name' => trim($data['counterparty_name']),
            'counterparty_email' => filled($data['counterparty_email'] ?? null)
                ? trim((string) $data['counterparty_email'])
                : null,
            'payment_method' => trim($data['payment_method']),
            'external_reference' => filled($data['external_reference'] ?? null)
                ? trim((string) $data['external_reference'])
                : null,
            'occurred_at' => $data['occurred_at'],
            'description' => trim($data['description']),
            'metadata' => ['manual_entry' => true],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تم تسجيل الحركة المالية في صفحة المدفوعات.',
            'data' => $entry,
        ], 201);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request, pagination: false);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('المدفوعات');
        $sheet->setRightToLeft(true);
        $sheet->freezePane('A6');

        $sheet->mergeCells('C1:O2');
        $sheet->setCellValue('C1', 'منصة زاد سينك — كشف التحويلات والمدفوعات');
        $sheet->getStyle('C1:O2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '173F8A']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->mergeCells('C3:O3');
        $sheet->setCellValue('C3', 'تاريخ الإصدار: '.now()->format('Y-m-d H:i'));
        $sheet->getStyle('C3:O3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $logoPath = $this->downloadLogo();
        if ($logoPath !== null) {
            try {
                $drawing = new Drawing();
                $drawing->setName('ZAD Sync');
                $drawing->setPath($logoPath);
                $drawing->setHeight(70);
                $drawing->setCoordinates('A1');
                $drawing->setWorksheet($sheet);
            } catch (Throwable $exception) {
                report($exception);
                @unlink($logoPath);
                $logoPath = null;
            }
        }

        $headers = [
            'المرجع',
            'التاريخ',
            'النوع',
            'الاتجاه',
            'الحالة',
            'الطرف/المستفيد',
            'رقم الطلب',
            'المبلغ',
            'الرسوم',
            'الصافي',
            'العملة',
            'طريقة الدفع',
            'بوابة/بنك',
            'مرجع المزود',
            'الوصف',
        ];
        $sheet->fromArray($headers, null, 'A5');
        $sheet->getStyle('A5:O5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '173F8A'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D7DEE8'],
                ],
            ],
        ]);

        $rowNumber = 6;
        foreach ($this->register->exportQuery($filters)->cursor() as $row) {
            $payload = $this->register->payload($row);
            $sheet->setCellValueExplicit('A'.$rowNumber, (string) $payload['reference'], DataType::TYPE_STRING);
            $sheet->fromArray([
                $payload['paid_at'],
                $payload['category'],
                $payload['direction'],
                $payload['status'],
                $payload['beneficiary_name'],
                $payload['order_number'],
                $payload['amount'],
                $payload['fee_amount'],
                $payload['net_amount'],
                $payload['currency'],
                $payload['method'],
                $payload['gateway_name'],
                $payload['transaction_id'],
                $payload['description'],
            ], null, 'B'.$rowNumber);
            $rowNumber++;
        }

        $lastRow = max(5, $rowNumber - 1);
        $sheet->setAutoFilter("A5:O{$lastRow}");
        $sheet->getStyle("A5:O{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        if ($lastRow >= 6) {
            $sheet->getStyle("H6:J{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("A6:O{$lastRow}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_HAIR)
                ->getColor()->setRGB('E5E7EB');
        }

        foreach (range('A', 'O') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getColumnDimension('O')->setWidth(42);

        $path = tempnam(sys_get_temp_dir(), 'zad-payments-');
        if ($path === false) {
            abort(500, 'تعذر إنشاء ملف التصدير.');
        }
        $xlsxPath = $path.'.xlsx';
        @unlink($path);

        try {
            (new Xlsx($spreadsheet))->save($xlsxPath);
        } finally {
            $spreadsheet->disconnectWorksheets();
            if ($logoPath !== null) {
                @unlink($logoPath);
            }
        }

        return response()
            ->download(
                $xlsxPath,
                'ZAD-Payments-'.now()->format('Y-m-d-His').'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            )
            ->deleteFileAfterSend(true);
    }

    private function filters(Request $request, bool $pagination): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['all', 'paid', 'pending', 'failed', 'refunded', 'partially_refunded', 'cancelled'])],
            'method' => ['nullable', 'string', 'max:80'],
            'gateway' => ['nullable', 'string', 'max:150'],
            'direction' => ['nullable', Rule::in(['all', 'inbound', 'outbound'])],
            'category' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];

        if ($pagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:100'];
        }

        return $request->validate($rules);
    }

    private function downloadLogo(): ?string
    {
        $branding = BrandingSetting::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();
        $url = $branding?->dashboard_logo_url ?: $branding?->primary_logo_url;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            $url = rtrim((string) config('app.url'), '/').$url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (
            $scheme !== 'https'
            || $host === ''
            || ($host !== 'res.cloudinary.com' && $host !== $appHost)
        ) {
            return null;
        }

        try {
            $response = Http::timeout(8)->retry(1, 200)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $path = tempnam(sys_get_temp_dir(), 'zad-logo-');
            if ($path === false) {
                return null;
            }
            file_put_contents($path, $response->body());

            return $path;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
