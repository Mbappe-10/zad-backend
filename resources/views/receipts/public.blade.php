@php
    $issued = $r['issued_at'] ? \Carbon\Carbon::parse($r['issued_at'])->timezone('Asia/Riyadh') : null;
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>إيصال {{ $r['number'] }} — زاد سينك</title>
<style>
    body{margin:0;background:#F8F9FB;color:#102B4E;font-family:-apple-system,"Segoe UI",Tahoma,Arial,sans-serif}
    .wrap{max-width:480px;margin:0 auto;padding:18px 16px 32px;display:flex;flex-direction:column;gap:14px}
    .card{background:#fff;border:1px solid #E6E9EE;border-radius:18px;padding:16px}
    .row{display:flex;justify-content:space-between;gap:10px;padding:4px 0;font-size:14.5px}
    .muted{color:#788292}
    .btn{display:block;text-align:center;text-decoration:none;font-weight:800;border-radius:12px;padding:13px;font-size:15px}
    .tag{font-size:12px;font-weight:800;border-radius:20px;padding:4px 12px}
</style>
</head>
<body><div class="wrap">
    <div style="display:flex;align-items:center;gap:10px">
        <b style="font-size:20px;flex:1">إيصال بيع — زاد سينك</b>
        <span class="tag" style="{{ $r['status'] === 'paid' ? 'color:#1BAA4B;background:#E8F8ED' : 'color:#D32F2F;background:#FDECEC' }}">{{ $r['status_label'] }}</span>
    </div>
    <div class="card">
        <div class="row"><span class="muted">المتجر</span><b>{{ $r['store_name'] ?: 'متجر الأسرة' }}</b></div>
        <div class="row"><span class="muted">رقم الإيصال</span><b dir="ltr">{{ $r['number'] }}</b></div>
        @if($r['order_number'])<div class="row"><span class="muted">رقم الطلب</span><b dir="ltr">{{ $r['order_number'] }}</b></div>@endif
        <div class="row"><span class="muted">التاريخ</span><b>{{ $issued?->format('Y/m/d — h:i A') }}</b></div>
        <div class="row"><span class="muted">نوع البيع</span><b>{{ $r['sale_type_label'] }} — {{ $r['channel_label'] }}</b></div>
        @if(!empty($r['staff_name']))<div class="row"><span class="muted">الكاشير</span><b>{{ $r['staff_name'] }}</b></div>@endif
        <div style="border-top:1px dashed #E6E9EE;margin:10px 0"></div>
        @foreach($r['items'] as $item)
            <div class="row"><span>{{ $item['quantity'] }} × {{ $item['name'] }}</span><b>{{ $money($item['total']) }} ر.س</b></div>
        @endforeach
        <div style="border-top:1px dashed #E6E9EE;margin:10px 0"></div>
        @if($r['discount'] > 0)<div class="row"><span class="muted">الخصم</span><b>{{ $money($r['discount']) }} ر.س</b></div>@endif
        <div class="row" style="font-size:18px"><b>الإجمالي ({{ $r['payment_method_label'] }})</b><b>{{ $money($r['total']) }} ر.س</b></div>
        <div class="muted" style="font-size:12px;margin-top:6px">إيصال بيع غير ضريبي</div>
    </div>
    <a class="btn" style="background:#fff;border:1px solid #E6E9EE;color:#102B4E" href="{{ $pdfUrl }}">تحميل PDF</a>
    @if($whatsapp)
        <a class="btn" style="background:#E8F8ED;border:1px solid #BFE8CC;color:#128C4A" href="{{ $whatsapp }}" rel="noopener">تواصل مع زاد سينك واتساب</a>
        <div class="muted" style="font-size:12px;text-align:center;margin-top:-6px">تنفتح المحادثة ومعها رقم الإيصال ورقم الطلب</div>
    @endif
    @if($appUrl)
        <a class="btn" style="background:#FF7900;color:#fff" href="{{ $appUrl }}">افتح زاد سينك واطلب مرة ثانية</a>
    @endif
</div></body>
</html>
