@php
    $issued = $c['issued_at'] ? \Carbon\Carbon::parse($c['issued_at'])->timezone('Asia/Riyadh') : null;
    $money = fn ($v) => number_format((float) $v, 2);
    $tax = $r['tax'] ?? null;
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $c['is_credit_note'] ? 'إشعار دائن' : 'مرتجع' }} {{ $c['number'] }}</title>
<style>
    body{margin:0;background:#F8F9FB;color:#102B4E;font-family:-apple-system,"Segoe UI",Tahoma,Arial,sans-serif}
    .wrap{max-width:480px;margin:0 auto;padding:18px 16px 32px;display:flex;flex-direction:column;gap:14px}
    .card{background:#fff;border:1px solid #E6E9EE;border-radius:18px;padding:16px}
    .row{display:flex;justify-content:space-between;gap:10px;padding:4px 0;font-size:14.5px}
    .muted{color:#788292}
    .btn{display:block;text-align:center;text-decoration:none;font-weight:800;border-radius:12px;padding:13px;font-size:15px;background:#fff;border:1px solid #E6E9EE;color:#102B4E}
</style>
</head>
<body><div class="wrap">
    <b style="font-size:20px">{{ $c['is_credit_note'] ? ($tax && $tax['title'] === 'فاتورة ضريبية' ? 'إشعار دائن' : 'إشعار دائن مبسط') : 'مرتجع' }} — {{ $r['store_name'] ?: 'المتجر' }}</b>
    <div class="card">
        @if($tax)<div class="row"><span class="muted">الرقم الضريبي للمتجر</span><b dir="ltr">{{ $tax['seller_vat_number'] }}</b></div>@endif
        <div class="row"><span class="muted">رقم الإشعار</span><b dir="ltr">{{ $c['number'] }}</b></div>
        <div class="row"><span class="muted">على الفاتورة</span><b dir="ltr">{{ $r['number'] }}</b></div>
        <div class="row"><span class="muted">التاريخ</span><b>{{ $issued?->format('Y/m/d — h:i A') }}</b></div>
        <div class="row"><span class="muted">السبب</span><b>{{ $c['reason'] }}</b></div>
        <div class="row"><span class="muted">طريقة الإرجاع</span><b>{{ $c['refund_method_label'] }}</b></div>
        <div style="border-top:1px dashed #E6E9EE;margin:10px 0"></div>
        @foreach($c['items'] as $item)
            <div class="row"><span>{{ $item['quantity'] }} × {{ $item['name'] }}</span><b>{{ $money($item['total']) }} ر.س</b></div>
        @endforeach
        <div style="border-top:1px dashed #E6E9EE;margin:10px 0"></div>
        @if($c['discount'] > 0)<div class="row"><span class="muted">حصة الخصم</span><b>-{{ $money($c['discount']) }} ر.س</b></div>@endif
        @if($c['vat_amount'] > 0)<div class="row"><span class="muted">ضريبة القيمة المضافة ١٥٪</span><b>{{ $money($c['vat_amount']) }} ر.س</b></div>@endif
        <div class="row" style="font-size:18px"><b>المبلغ المرتجع</b><b>{{ $money($c['total']) }} ر.س</b></div>
        @if($qrSvg)<div style="display:flex;justify-content:center;margin-top:12px">{!! $qrSvg !!}</div>@endif
    </div>
    <a class="btn" href="{{ $receiptUrl }}">عرض الفاتورة الأصلية</a>
</div></body>
</html>
