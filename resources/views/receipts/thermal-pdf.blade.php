@php
    $issued = $r['issued_at'] ? \Carbon\Carbon::parse($r['issued_at'])->timezone('Asia/Riyadh') : null;
    $money = fn ($v) => number_format((float) $v, 2);
    $tax = $r['tax'] ?? null;
    $brand = ! $t['hide_zad_branding'];
    $count = array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 0), $r['items']));
    $vatRate = $tax ? $tax['rate'] / 100 : 0;
@endphp
<html><head><style>
    body{font-family:sans-serif;font-size:9pt;color:#111}
    .c{text-align:center}.mu{color:#666}
    td{padding:1.2mm 0;vertical-align:top}
    .n{direction:ltr;text-align:left;}
    .hr{border-top:0.3mm dashed #999;margin:2mm 0}
    .hr2{border-top:0.4mm solid #111;margin:1.6mm 0}
</style></head><body>
@if($brand)<div class="c" style="background:#102B4E;color:#fff;padding:1.5mm;font-size:8.5pt">زاد سينك — إيصال رقمي موثّق</div>@endif
<div class="c" style="margin-top:2mm">
    @if(!empty($images['store']) && $t['show_logo'])<img src="{{ $images['store'] }}" style="width:20mm;height:20mm"><br>@endif
    <b style="font-size:13pt">{{ $t['store_name'] }}</b>
    @if($tax)
        @if($tax['seller_trade_name'])<br><span class="mu">{{ $tax['seller_trade_name'] }}</span>@endif
        <br>الرقم الضريبي: <b>{{ $tax['seller_vat_number'] }}</b>
        <br><b>{{ $tax['title'] }}</b>
    @endif
    <div style="border:0.6mm solid #111;margin:2mm 14mm;padding:1.2mm;font-size:15pt;font-weight:bold;">Order# {{ $t['order_label'] }}</div>
</div>
<table width="100%">
    <tr><td class="mu">التاريخ</td><td class="n"><b>{{ $issued?->format('Y/m/d h:i A') }}</b></td></tr>
    <tr><td class="mu">رقم الإيصال</td><td class="n"><b>{{ $r['number'] }}</b></td></tr>
    <tr><td class="mu">نوع البيع</td><td style="text-align:left"><b>{{ $r['sale_type_label'] }} — {{ $r['channel_label'] }}</b></td></tr>
    @if(!empty($r['staff_name']))<tr><td class="mu">الكاشير</td><td style="text-align:left"><b>{{ $r['staff_name'] }}</b></td></tr>@endif
    @if(!empty($r['customer_name']))<tr><td class="mu">العميل</td><td style="text-align:left"><b>{{ $r['customer_name'] }}</b></td></tr>@endif
</table>
<div class="hr"></div>
<table width="100%">
    <tr style="font-size:7pt" class="mu"><td width="10%">الكمية</td><td>المنتج</td><td class="n" width="22%">سعر الوحدة</td><td class="n" width="22%">{{ $tax ? 'شامل الضريبة' : 'المبلغ' }}</td></tr>
    @foreach($r['items'] as $item)
        @php $en = $t['english'][$item['product_id'] ?? 0] ?? null; @endphp
        <tr><td>{{ $item['quantity'] }}</td><td>{{ $item['name'] }}@if($en)<br><span class="mu" style="font-size:7pt">{{ $en }}</span>@endif</td>
            <td class="n">{{ $money($item['unit_price']) }}</td><td class="n">{{ $money($tax ? $item['total'] * (1 + $vatRate) : $item['total']) }}</td></tr>
    @endforeach
</table>
<div class="hr2"></div>
<table width="100%">
    <tr><td>المجموع الفرعي</td><td class="n">{{ $money($r['subtotal']) }}</td></tr>
    @if($r['discount'] > 0)<tr><td>الخصم</td><td class="n">-{{ $money($r['discount']) }}</td></tr>@endif
    @if($tax)
        <tr><td>المبلغ الخاضع للضريبة</td><td class="n">{{ $money($tax['net']) }}</td></tr>
        <tr><td>ضريبة القيمة المضافة ({{ (int) $tax['rate'] }}%)</td><td class="n">{{ $money($tax['vat']) }}</td></tr>
    @endif
    <tr><td colspan="2"><div class="hr2"></div></td></tr>
    <tr style="font-size:11pt"><td><b>{{ $tax ? 'الإجمالي شامل الضريبة' : 'الإجمالي' }}</b></td><td class="n"><b>{{ $money($r['total']) }} SAR</b></td></tr>
    <tr><td>طريقة الدفع</td><td style="text-align:left"><b>{{ $r['payment_method_label'] }}</b></td></tr>
    <tr><td>عدد المنتجات</td><td class="n"><b>{{ $count }}</b></td></tr>
</table>
@if($t['address_text'] || $t['social'])
    <div class="hr"></div>
    <div class="c mu">@if($t['address_text']){{ $t['address_text'] }}@endif @if($t['social'])<br>{{ $t['social'] }}@endif</div>
@endif
@if($tax && $t['show_tax_qr'] && $qr)
    <div class="c" style="margin-top:2mm"><barcode code="{{ $tax['qr'] }}" type="QR" size="0.8" error="M" disableborder="1" /></div>
@endif
@if($t['thank_you'])<div class="c" style="font-size:10.5pt;margin-top:2mm"><b>{{ $t['thank_you'] }}</b></div>@endif
@if($brand)
    <div class="c" style="margin-top:3mm;background:#102B4E;color:#fff;padding:2.5mm">
        @if(!empty($images['logo']))<img src="{{ $images['logo'] }}" style="width:11mm;height:11mm"><br>@endif
        <b>اطلب من {{ $t['store_name'] }} أونلاين</b><br><span style="font-size:7.5pt">من تطبيق زاد سينك — ويوصلك لباب البيت</span>
    </div>
    <div class="c mu" style="font-size:7pt;margin-top:1.5mm">بواسطة كاشير زاد سينك برو</div>
@endif
</body></html>
