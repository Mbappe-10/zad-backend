@php
    $issued = $r['issued_at'] ? \Carbon\Carbon::parse($r['issued_at'])->timezone('Asia/Riyadh') : null;
    $money = fn ($v) => number_format((float) $v, 2);
    $tax = $r['tax'] ?? null;
    $brand = ! $t['hide_zad_branding'];
    $qrSvg = ($tax && $t['show_tax_qr']) ? app(\App\Services\App\StoreTaxService::class)->qrSvg($tax['qr'], 190) : null;
    $appQr = $brand ? app(\App\Services\App\StoreTaxService::class)->qrSvg($t['store_url'], 96) : null;
    $count = array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 0), $r['items']));
    $vatRate = $tax ? $tax['rate'] / 100 : 0;
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $tax ? $tax['title'] : 'إيصال بيع' }} {{ $r['number'] }} — {{ $t['store_name'] }}</title>
<style>
    *{box-sizing:border-box}
    body{margin:0;background:#EEF0F3;color:#1d1d1d;font-family:-apple-system,"Segoe UI",Tahoma,Arial,sans-serif}
    .wrap{max-width:460px;margin:0 auto;padding:22px 12px 30px}
    .paper{background:#fff;padding:26px 22px 24px;position:relative;box-shadow:0 14px 34px rgba(16,43,78,.12);font-size:15px}
    .paper:before,.paper:after{content:"";position:absolute;left:0;right:0;height:12px;background:linear-gradient(135deg,transparent 50%,#fff 50%) 0 0/12px 12px,linear-gradient(-135deg,transparent 50%,#fff 50%) 0 0/12px 12px}
    .paper:before{top:-12px}.paper:after{bottom:-12px;transform:rotate(180deg)}
    .c{text-align:center}.mu{color:#6b7280}
    .zh{display:flex;align-items:center;gap:8px;background:#102B4E;color:#fff;border-radius:14px;padding:7px 12px;margin:-4px -4px 16px}
    .zh img{width:32px;height:32px;border-radius:8px;background:#fff}.zh b{font-size:16px}
    .zh span{margin-right:auto;font-size:12px;background:#FF7900;border-radius:20px;padding:3px 10px;font-weight:800}
    .logo{width:96px;height:96px;border-radius:22px;object-fit:cover;display:block;margin:0 auto 8px}
    .sname{font-size:24px;font-weight:900}
    .box{border:3px solid #1d1d1d;display:inline-block;padding:6px 22px;font-size:30px;font-weight:900;margin:14px 0 8px;direction:ltr;font-family:Arial,sans-serif}
    .hr{border-top:2px dashed #b9bec7;margin:12px 0}.hr2{border-top:2px solid #1d1d1d;margin:10px 0}
    .row{display:flex;justify-content:space-between;gap:10px;margin:4px 0}
    .n{direction:ltr;font-family:Arial,sans-serif}
    table{width:100%;border-collapse:collapse;font-size:14px}
    th{font-size:11.5px;font-weight:700;color:#4b5563;text-align:right;padding-bottom:5px;vertical-align:bottom;line-height:1.25}
    th.n,td.n{text-align:left}
    td{padding:6px 0;vertical-align:top}
    td small{display:block;color:#6b7280;font-size:11.5px;direction:ltr;text-align:right;font-family:Arial,sans-serif}
    .tot{font-size:19px;font-weight:900}
    .qr{display:flex;justify-content:center;margin-top:8px}
    .zad{margin-top:16px;background:linear-gradient(135deg,#102B4E,#1D4778);color:#fff;border-radius:18px;padding:18px 16px;text-align:center}
    .zad .zl{width:60px;height:60px;border-radius:15px;background:#fff;display:block;margin:0 auto 6px}
    .zad b{font-size:19px;display:block}.zad span{font-size:13px;color:#C9D3E3;display:block;margin-top:3px;line-height:1.5}
    .zad .r2{display:flex;align-items:center;gap:10px;margin-top:12px;background:rgba(255,255,255,.08);border-radius:12px;padding:8px;text-align:right}
    .zad .r2 .q{background:#fff;border-radius:8px;padding:3px;line-height:0}
    .btn{display:block;text-decoration:none;text-align:center;border-radius:12px;padding:12px;font-weight:900;font-size:16px;margin-top:12px}
    .green{margin-top:12px;font-size:13px;color:#1BAA4B;font-weight:800}
</style>
</head>
<body><div class="wrap">
<div class="paper">
    @if($brand)
        <div class="zh"><img src="{{ url('/img/zad-receipt-logo.png') }}" alt=""><b>زاد سينك</b><span>إيصال رقمي موثّق</span></div>
    @endif
    <div class="c">
        @if($t['logo'])<img class="logo" src="{{ $t['logo'] }}" alt="">@endif
        <div class="sname">{{ $t['store_name'] }}@if($t['show_english'] && $t['store_name_en'] && $t['store_name_en'] !== $t['store_name']) | {{ $t['store_name_en'] }}@endif</div>
        @if($tax)
            @if($tax['seller_trade_name'])<div class="mu">{{ $tax['seller_trade_name'] }}</div>@endif
            <div style="margin-top:4px">الرقم الضريبي: <b class="n">{{ $tax['seller_vat_number'] }}</b></div>
            <div style="font-weight:900;margin-top:3px">{{ $tax['title'] }}@if($t['show_english']) <span class="mu" style="font-weight:400;font-size:12px">{{ $tax['title_en'] }}</span>@endif</div>
        @endif
        <div class="box">Order# {{ $t['order_label'] }}</div>
    </div>
    <div class="row"><span class="mu">التاريخ</span><b class="n">{{ $issued?->format('Y/m/d h:i A') }}</b></div>
    <div class="row"><span class="mu">رقم الإيصال</span><b class="n">{{ $r['number'] }}</b></div>
    <div class="row"><span class="mu">نوع البيع</span><b>{{ $r['sale_type_label'] }} — {{ $r['channel_label'] }}</b></div>
    @if(!empty($r['staff_name']))<div class="row"><span class="mu">الكاشير</span><b>{{ $r['staff_name'] }}</b></div>@endif
    @if(!empty($r['customer_name']))<div class="row"><span class="mu">العميل</span><b>{{ $r['customer_name'] }}</b></div>@endif
    @if(!empty($r['customer_vat_number']))<div class="row"><span class="mu">الرقم الضريبي للعميل</span><b class="n">{{ $r['customer_vat_number'] }}</b></div>@endif
    <div class="hr"></div>
    <table>
        <tr><th>الكمية</th><th>المنتج</th><th class="n">سعر الوحدة</th><th class="n">{{ $tax ? 'شامل الضريبة' : 'المبلغ' }}</th></tr>
        @foreach($r['items'] as $item)
            @php $en = $t['english'][$item['product_id'] ?? 0] ?? null; @endphp
            <tr>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ $item['name'] }}@if($en)<small>{{ $en }}</small>@endif</td>
                <td class="n">{{ $money($item['unit_price']) }}</td>
                <td class="n">{{ $money($tax ? $item['total'] * (1 + $vatRate) : $item['total']) }}</td>
            </tr>
        @endforeach
    </table>
    <div class="hr2"></div>
    <div class="row"><span>المجموع الفرعي</span><b class="n">{{ $money($r['subtotal']) }}</b></div>
    @if($r['discount'] > 0)<div class="row"><span>الخصم</span><b class="n">-{{ $money($r['discount']) }}</b></div>@endif
    @if($tax)
        <div class="row"><span>المبلغ الخاضع للضريبة</span><b class="n">{{ $money($tax['net']) }}</b></div>
        <div class="row"><span>ضريبة القيمة المضافة ({{ (int) $tax['rate'] }}٪)</span><b class="n">{{ $money($tax['vat']) }}</b></div>
    @endif
    <div class="hr2"></div>
    <div class="row tot"><span>{{ $tax ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</span><span class="n">{{ $money($r['total']) }} ر.س</span></div>
    @if(($r['returned_total'] ?? 0) > 0)<div class="row" style="color:#D32F2F"><span>مرتجع (إشعار دائن)</span><b class="n">-{{ $money($r['returned_total']) }}</b></div>@endif
    <div class="row"><span>طريقة الدفع</span><b>{{ $r['payment_method_label'] }}</b></div>
    <div class="row"><span>عدد المنتجات</span><b>{{ $count }}</b></div>
    @if($r['status'] !== 'paid')<div class="row"><span>الحالة</span><b style="color:#D32F2F">{{ $r['status_label'] }}</b></div>@endif
    @if($t['address_text'] || $t['social'])
        <div class="hr"></div>
        <div class="c mu" style="line-height:1.6">
            @if($t['address_text']){{ $t['address_text'] }}<br>@endif
            @if($t['social'])تابعونا: <span class="n">{{ $t['social'] }}</span>@endif
        </div>
    @endif
    @if($qrSvg)
        <div class="qr">{!! $qrSvg !!}</div>
        <div class="c mu" style="font-size:11.5px">باركود الفاتورة الضريبية</div>
    @elseif(! $tax)
        <div class="c mu" style="font-size:12px;margin-top:8px">إيصال بيع غير ضريبي</div>
    @endif
    @if($t['thank_you'])<div class="c" style="font-size:17px;font-weight:900;margin-top:12px">{{ $t['thank_you'] }}</div>@endif
    @if($brand)
        <div class="zad">
            <img class="zl" src="{{ url('/img/zad-receipt-logo.png') }}" alt="">
            <b>اطلب من {{ $t['store_name'] }} أونلاين</b>
            <span>من تطبيق زاد سينك — ويوصلك لباب البيت</span>
            @if($appQr)<div class="r2"><div class="q">{!! $appQr !!}</div><div><b style="font-size:15px">صوّر وافتح المتجر</b><span>تابع طلباتك وإيصالاتك في مكان واحد</span></div></div>@endif
            <a class="btn" style="background:#FF7900;color:#fff" href="{{ $t['store_url'] }}">اطلب مرة ثانية</a>
        </div>
    @endif
    <div class="c green">🌱 إيصال رقمي — بدون ورق</div>
    @if($brand)<div class="c mu" style="font-size:11.5px;margin-top:4px">بواسطة كاشير زاد سينك برو</div>@endif
</div>
<a class="btn" style="background:#fff;border:1px solid #E6E9EE;color:#102B4E;margin-top:22px" href="{{ $pdfUrl }}">تحميل PDF</a>
@if($brand && $whatsapp)
    <a class="btn" style="background:#E8F8ED;border:1px solid #BFE8CC;color:#128C4A" href="{{ $whatsapp }}" rel="noopener">استفسار عن الإيصال — واتساب زاد سينك</a>
@endif
</div></body>
</html>
