@php
    $tz = 'Asia/Riyadh';
    $issued = $r['issued_at'] ? \Carbon\Carbon::parse($r['issued_at'])->timezone($tz) : null;
    $money = fn ($v) => number_format((float) $v, 2);
    $paid = $r['status'] === 'paid';
    $tax = $r['tax'] ?? null;
    $lineVat = $tax && (float) $r['discount'] <= 0;
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><style>
    body { font-family: sans-serif; font-size: 11pt; color: #102B4E; }
    .band { border-bottom: 3px solid #FF7900; padding-bottom: 10px; }
    .title { font-size: 20pt; font-weight: bold; color: #FF7900; }
    .muted { color: #788292; font-size: 9pt; }
    .meta { background: #F7F8FA; border-radius: 8px; }
    .meta td { padding: 7px 10px; vertical-align: top; }
    .label { color: #788292; font-size: 8.5pt; }
    .value { font-weight: bold; font-size: 10.5pt; }
    .box { border: 1px solid #E6E9EE; border-radius: 8px; padding: 9px 10px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
    table.items th { background: #102B4E; color: #fff; font-size: 9.5pt; padding: 8px; }
    table.items td { padding: 8px; border-bottom: 1px solid #E6E9EE; font-size: 10.5pt; }
    .c { text-align: center; } .l { text-align: left; }
    .sum td { padding: 4px 8px; font-size: 10.5pt; }
    .grand td { background: #FFF0E4; font-size: 13pt; font-weight: bold; padding: 9px 8px; }
    .stamp { color: #1BAA4B; border: 3px solid #1BAA4B; font-size: 18pt; font-weight: bold; padding: 4px 16px; border-radius: 8px; }
    .stamp-off { color: #D32F2F; border-color: #D32F2F; }
    .foot { border-top: 1px solid #E6E9EE; padding-top: 8px; color: #788292; font-size: 8.5pt; }
</style></head>
<body>
<table width="100%" class="band"><tr>
    <td width="12%">@if(!empty($images['logo']))<img src="{{ $images['logo'] }}" style="width:52px;height:52px" alt="logo">@endif</td>
    <td width="48%"><b style="font-size:16pt">زاد سينك</b><br><span class="muted">منصة الأسر المنتجة</span></td>
    <td width="40%" class="l"><span class="title">{{ $tax ? $tax['title'] : 'إيصال بيع' }}</span><br><span class="muted">{{ $tax ? $tax['title_en'] : 'Sales Receipt' }}</span></td>
</tr></table>

<table width="100%" class="meta" style="margin-top:12px" cellspacing="0">
    <tr>
        <td width="33%"><span class="label">رقم الإيصال</span><br><span class="value">{{ $r['number'] }}</span></td>
        <td width="33%"><span class="label">رقم الطلب</span><br><span class="value">{{ $r['order_number'] ?: '—' }}</span></td>
        <td width="34%"><span class="label">التاريخ والوقت (توقيت مكة)</span><br><span class="value">{{ $issued?->format('Y/m/d — h:i A') }}</span></td>
    </tr>
    <tr>
        <td><span class="label">نوع البيع</span><br><span class="value">{{ $r['sale_type_label'] }}</span></td>
        <td><span class="label">قناة البيع</span><br><span class="value">{{ $r['channel_label'] }}</span></td>
        <td><span class="label">طريقة الدفع</span><br><span class="value">{{ $r['payment_method_label'] }}</span></td>
    </tr>
    @if(!empty($r['staff_name']))
    <tr><td colspan="3"><span class="label">الكاشير</span><br><span class="value">{{ $r['staff_name'] }}</span></td></tr>
    @endif
</table>

<table width="100%" style="margin-top:12px" cellspacing="6"><tr>
    <td width="50%" class="box">
        @if(!empty($images['store']))<img src="{{ $images['store'] }}" style="width:34px;height:34px;vertical-align:middle" alt="">@endif
        <span class="label">البائع</span><br><span class="value">{{ $tax['seller_trade_name'] ?? null ?: ($r['store_name'] ?: 'متجر الأسرة') }}</span>
        @if($tax && $tax['seller_trade_name'] && $r['store_name'])<br><span class="muted">المتجر: {{ $r['store_name'] }}</span>@endif
        @if($tax)<br><span class="label">الرقم الضريبي</span> <b>{{ $tax['seller_vat_number'] }}</b>@endif
    </td>
    <td width="50%" class="box"><span class="label">العميل</span><br>
        @if(!empty($r['customer_name']))<span class="value">{{ $r['customer_name'] }}</span><br>@endif
        @if(!empty($r['customer_vat_number']))<span class="label">الرقم الضريبي</span> <b>{{ $r['customer_vat_number'] }}</b><br>@endif
        <span class="value" style="direction:ltr">{{ $r['customer'] ?: (empty($r['customer_name']) ? '—' : '') }}</span></td>
</tr></table>

<table class="items">
    <thead><tr><th style="text-align:right">المنتج</th><th class="c">الكمية</th><th class="c">السعر</th><th class="{{ $lineVat ? 'c' : 'l' }}">{{ $tax ? 'قبل الضريبة' : 'المجموع' }}</th>
        @if($lineVat)<th class="c">الضريبة {{ (int) $tax['rate'] }}٪</th><th class="l">الإجمالي</th>@endif</tr></thead>
    <tbody>
    @forelse($r['items'] as $item)
        @php $lv = $lineVat ? round($item['total'] * $tax['rate'] / 100, 2) : 0; @endphp
        <tr><td>{{ $item['name'] }}</td><td class="c">{{ $item['quantity'] }}</td><td class="c">{{ $money($item['unit_price']) }}</td><td class="{{ $lineVat ? 'c' : 'l' }}"><b>{{ $money($item['total']) }}</b></td>
            @if($lineVat)<td class="c">{{ $money($lv) }}</td><td class="l"><b>{{ $money($item['total'] + $lv) }}</b></td>@endif</tr>
    @empty
        <tr><td colspan="{{ $lineVat ? 6 : 4 }}" class="c muted">لا توجد أصناف</td></tr>
    @endforelse
    </tbody>
</table>

<table width="100%" style="margin-top:14px"><tr>
    <td width="40%" style="vertical-align:bottom">
        @if($qr)<barcode code="{{ $tax['qr'] ?? $r['url'] }}" type="QR" size="0.9" error="M" disableborder="1" /><br>@endif
        <span class="muted">{{ $tax ? 'رمز الفاتورة بصيغة هيئة الزكاة والضريبة والجمارك' : 'امسح للتحقق من الإيصال' }}</span>
    </td>
    <td width="60%">
        <table width="100%" class="sum" cellspacing="0">
            <tr><td>المجموع الفرعي</td><td class="l">{{ $money($r['subtotal']) }} ر.س</td></tr>
            @if($r['delivery_fee'] > 0)<tr><td>التوصيل</td><td class="l">{{ $money($r['delivery_fee']) }} ر.س</td></tr>@endif
            <tr><td>الخصم</td><td class="l">{{ $money($r['discount']) }} ر.س</td></tr>
            @if($tax)
            <tr><td>المجموع الخاضع للضريبة</td><td class="l">{{ $money($tax['net']) }} ر.س</td></tr>
            <tr><td>ضريبة القيمة المضافة {{ (int) $tax['rate'] }}٪</td><td class="l">{{ $money($tax['vat']) }} ر.س</td></tr>
            @endif
            <tr class="grand"><td>{{ $tax ? 'الإجمالي شامل الضريبة' : 'الإجمالي المدفوع' }}</td><td class="l" style="color:#FF7900">{{ $money($r['total']) }} ر.س</td></tr>
        </table>
        <div style="margin-top:14px;text-align:center"><span class="stamp {{ $paid ? '' : 'stamp-off' }}">{{ $r['status_label'] }}</span></div>
    </td>
</tr></table>

<div class="foot" style="margin-top:28px">{{ $tax ? $tax['title'].' صادرة عبر منصة زاد سينك' : 'إيصال بيع غير ضريبي صادر عبر منصة زاد سينك' }} — {{ $r['url'] }}</div>
</body>
</html>
