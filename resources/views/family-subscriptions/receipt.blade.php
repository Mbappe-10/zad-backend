<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><style>
body { font-family: sans-serif; font-size: 13px; color: #292524; }
h1 { color: #9a3412; font-size: 24px; } .box { border: 1px solid #e7e5e4; padding: 16px; margin: 16px 0; }
table { width: 100%; border-collapse: collapse; } td { border-bottom: 1px solid #e7e5e4; padding: 9px; }
.small { font-size: 10px; color: #57534e; } .mono { font-family: monospace; direction: ltr; text-align: left; }
</style></head>
<body>
@if(!empty($images['logo']))<div><img alt="ZAD" src="{{ $images['logo'] }}" style="max-width:150px;max-height:90px" /></div>@endif
<h1 style="color:{{ $receipt['accent_color'] ?? '#9A3412' }}">{{ $receipt['issuer_name'] }} — {{ $receipt['heading'] }}</h1>
<p>{{ $receipt['intro'] }}</p>
@if(!empty($images['image']) && ($receipt['layout'] ?? 'classic') === 'classic')
<div><img alt="" src="{{ $images['image'] }}" style="max-width:100%;max-height:125px" /></div>
@endif
<div class="box"><table>
<tr><td>رقم الفاتورة</td><td>{{ $receipt['number'] }}</td></tr>
<tr><td>اسم المتجر</td><td>{{ $receipt['store_name'] }}</td></tr>
<tr><td>اسم الأسرة</td><td>{{ $receipt['family_name'] }}</td></tr>
<tr><td>الباقة</td><td>{{ $receipt['plan_code'] }}</td></tr>
<tr><td>المدة</td><td>{{ $receipt['duration_days'] }} يوم</td></tr>
<tr><td>وقت تأكيد الدفع</td><td>{{ \Carbon\Carbon::parse($receipt['paid_at'])->timezone('Asia/Riyadh')->format('Y-m-d H:i:s') }} (مكة)</td></tr>
<tr><td>بداية الاشتراك</td><td>{{ \Carbon\Carbon::parse($receipt['starts_at'])->timezone('Asia/Riyadh')->format('Y-m-d H:i:s') }} (مكة)</td></tr>
<tr><td>نهاية الاشتراك</td><td>{{ \Carbon\Carbon::parse($receipt['ends_at'])->timezone('Asia/Riyadh')->format('Y-m-d H:i:s') }} (مكة)</td></tr>
<tr><td>المبلغ المدفوع</td><td>{{ number_format($receipt['amount_halalas'] / 100, 2) }} {{ $receipt['currency'] }}</td></tr>
<tr><td>بوابة الدفع</td><td>ميسر — {{ $receipt['payment_mode'] }}</td></tr>
<tr><td>معرّف عملية ميسر</td><td class="mono">{{ $receipt['moyasar_payment_id'] }}</td></tr>
</table></div>
<p>{{ $receipt['footer'] }}</p>
<p class="small">بصمة بيانات الفاتورة SHA-256:</p><p class="small mono">{{ $fingerprint }}</p>
</body></html>
