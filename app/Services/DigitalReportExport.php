<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DigitalReportExport
{
    public function download(object $run, string $format)
    {
        $report = json_decode($run->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $state = match ($run->status) {
            'completed' => 'تمت مراجعة التقرير واعتماده', 'rejected' => 'تقرير مرفوض', default => 'مسودة — بانتظار المراجعة',
        };
        $logo = storage_path('app/private/digital-reports/zad-logo.png');
        abort_unless(is_file($logo), 503, 'شعار التقرير غير مثبت. أعد تشغيل مثبت التحديث.');
        $filename = 'zad-sync-report-'.$run->id.'.'.$format;
        if ($format === 'pdf') {
            $temp = storage_path('app/private/digital-reports/mpdf');
            File::ensureDirectoryExists($temp);
            $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp,
                'autoScriptToLang' => true, 'autoLangToFont' => true, 'margin_top' => 15, 'margin_bottom' => 20]);
            $pdf->SetDirectionality('rtl');
            $pdf->SetTitle($report['title']);
            $pdf->SetAuthor('ZAD Sync');
            $pdf->SetHTMLFooter('<div style="text-align:center;font-size:9pt">ZAD Sync — {PAGENO} / {nbpg}</div>');
            $pdf->WriteHTML(view('digital-reports.summary', compact('report', 'run', 'state', 'logo'))->render());
            return response($pdf->Output('', 'S'), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store',
            ]);
        }
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('ZAD Sync')->setTitle($report['title']);
        $sheet = $book->getActiveSheet()->setTitle('ملخص التقرير');
        $sheet->setRightToLeft(true);
        $image = new Drawing();
        $image->setName('ZAD Sync'); $image->setPath($logo); $image->setHeight(56); $image->setCoordinates('C1'); $image->setWorksheet($sheet);
        $sheet->getRowDimension(1)->setRowHeight(50);
        $rows = [
            ['ZAD Sync — زاد سنك', '', ''], [$report['title'], '', ''], [$state, '', ''],
            ['رقم التنفيذ', (string) $run->id, ''], ['المصدر', $report['source'], ''],
            ['وقت استخراج البيانات', $report['captured_at'], ''], ['بداية الفترة', $report['from'], ''],
            ['نهاية الفترة غير المشمولة', $report['to_exclusive'], ''], ['المنطقة الزمنية', $report['timezone'], ''],
            ['المؤشر', 'القيمة', 'الوحدة'], ...$report['metrics'],
            ['ملاحظة المراجع', $run->review_note ?? 'لم تسجل ملاحظة', ''],
            ['تاريخ المراجعة UTC', $run->reviewed_at ?? '—', ''],
            ['معرف المراجع', (string) ($run->reviewed_by ?? '—'), ''],
        ];
        foreach ($report['notes'] as $note) $rows[] = [$note, '', ''];
        foreach ($rows as $i => $row) {
            foreach ($row as $j => $value) {
                $cell = chr(65 + $j).($i + 1);
                // Only the fixed metric-value cells are numeric. User text can never become a formula.
                if ($j === 1 && $i >= 10 && $i < 10 + count($report['metrics'])) {
                    $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($row[2] === 'ر.س' ? '#,##0.00' : '#,##0');
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $sheet->getColumnDimension('A')->setWidth(72);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getStyle('A1:C'.count($rows))->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1:C'.count($rows))->getFont()->setName('Arial')->setSize(11);
        $sheet->getStyle('A10:C10')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A10:C10')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF6326D6');
        for ($i = 1; $i <= count($rows); $i++) {
            if ($i > 1) $sheet->getRowDimension($i)->setRowHeight(36);
            if ($i > 22) { $sheet->mergeCells('A'.$i.':C'.$i); $sheet->getRowDimension($i)->setRowHeight(42); }
        }
        $sheet->freezePane('B11');
        return response()->streamDownload(function () use ($book) {
            try { (new Xlsx($book))->save('php://output'); }
            finally { $book->disconnectWorksheets(); }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
}
