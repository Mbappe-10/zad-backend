<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body { font-size: 11pt; color: #202238; } h1 { color: #6326d6; font-size: 19pt; }
table { border-collapse: collapse; width: 100%; margin-top: 15px; }
th { background: #6326d6; color: white; } td,th { padding: 9px; border: 1px solid #ddd; }
.note { color: #555; font-size: 9pt; } .state { padding: 10px; background: #eee7fa; }
</style></head><body>
<img src="{{ $logo }}" style="height:60px" alt="ZAD Sync">
<h1>{{ $report['title'] }}</h1>
<p class="state">{{ $state }} — رقم التنفيذ {{ $run->id }}</p>
<p>المصدر: {{ $report['source'] }}<br>وقت استخراج البيانات: {{ $report['captured_at'] }}<br>
الفترة: {{ $report['from'] }} إلى {{ $report['to_exclusive'] }} (النهاية غير مشمولة)<br>التوقيت: {{ $report['timezone'] }}</p>
<table><thead><tr><th>المؤشر</th><th>القيمة</th><th>الوحدة</th></tr></thead><tbody>
@foreach ($report['metrics'] as $metric)
<tr><td>{{ $metric[0] }}</td><td>{{ $metric[1] }}</td><td>{{ $metric[2] }}</td></tr>
@endforeach
</tbody></table>
<p>ملاحظة المراجع: {{ $run->review_note ?? 'لم تسجل ملاحظة' }}</p>
@if ($run->reviewed_at)<p>تاريخ المراجعة UTC: {{ $run->reviewed_at }} — معرف المراجع: {{ $run->reviewed_by }}</p>@endif
@foreach ($report['notes'] as $note)<p class="note">• {{ $note }}</p>@endforeach
</body></html>
