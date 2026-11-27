@php
    $accent = $receipt['accent_color'] ?? '#9A3412';
    $tz = 'Asia/Riyadh';
    $at = fn ($value) => $value ? \Carbon\Carbon::parse($value)->timezone($tz) : null;
    $paid = $at($receipt['paid_at'] ?? null);
    $issued = $at($receipt['issued_at'] ?? ($receipt['paid_at'] ?? null));
    $starts = $at($receipt['starts_at'] ?? null);
    $ends = $at($receipt['ends_at'] ?? null);
    $sar = fn ($halalas) => number_format(((int) $halalas) / 100, 2);
    $original = $receipt['original_amount_halalas'] ?? $receipt['amount_halalas'];
    $discount = (int) ($receipt['discount_halalas'] ?? 0);
    $planNames = ['silver' => 'الباقة الفضية', 'gold' => 'الباقة الذهبية', 'free' => 'الباقة المجانية'];
    $planName = $receipt['plan_name'] ?? ($planNames[$receipt['plan_code']] ?? $receipt['plan_code']);
    $modes = ['live' => 'ميسر — دفع إلكتروني', 'sandbox' => 'ميسر — بيئة تجريبية', 'coupon' => 'خصم كامل بكود — بدون دفع'];
    $method = $modes[$receipt['payment_mode'] ?? ''] ?? ($receipt['payment_mode'] ?? '');
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><style>
    body { font-family: sans-serif; font-size: 11.5pt; color: #1c1917; }
    .band { background: {{ $accent }}; height: 6px; }
    .head td { vertical-align: middle; }
    .title { font-size: 20pt; font-weight: bold; color: {{ $accent }}; }
    .muted { color: #78716c; font-size: 9.5pt; }
    .meta td { padding: 3px 0; font-size: 10.5pt; }
    .card { border: 1px solid #e7e5e4; border-radius: 6px; padding: 10px 12px; }
    .label { color: #78716c; font-size: 9pt; }
    .value { font-weight: bold; font-size: 11pt; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.items th { background: #f5f5f4; color: #44403c; font-size: 9.5pt; padding: 8px; text-align: right; border-bottom: 2px solid {{ $accent }}; }
    table.items td { padding: 9px 8px; border-bottom: 1px solid #e7e5e4; font-size: 10.5pt; }
    .num { text-align: left; direction: ltr; }
    .total td { font-weight: bold; font-size: 12.5pt; color: {{ $accent }}; border-bottom: none; }
    .mono { font-family: monospace; direction: ltr; text-align: left; font-size: 8pt; color: #57534e; }
    .paid { color: #15803d; font-weight: bold; border: 2px solid #15803d; padding: 3px 10px; border-radius: 4px; }
</style></head>
<body>
<div class="band"></div>
<table class="head" width="100%" style="margin-top:12px"><tr>
    <td width="55%">
        <div class="title">{{ $receipt['heading'] }}</div>
        <div class="muted">{{ $receipt['issuer_name'] }}</div>
        <div style="margin-top:8px"><span class="paid">{{ ($receipt['payment_mode'] ?? '') === 'coupon' ? 'مُفعّلة بالكامل' : 'مدفوعة' }}</span></div>
    </td>
    <td width="45%" style="text-align:left">
        @if(!empty($images['logo']))<img alt="logo" src="{{ $images['logo'] }}" style="max-width:150px;max-height:80px" />@endif
    </td>
</tr></table>

<table class="meta" width="100%" style="margin-top:14px"><tr>
    <td width="34%"><span class="label">رقم الفاتورة</span><br><span class="value">{{ $receipt['number'] }}</span></td>
    <td width="33%"><span class="label">تاريخ الإصدار</span><br><span class="value">{{ $issued?->format('Y/m/d') }}</span></td>
    <td width="33%"><span class="label">وقت الإصدار (توقيت مكة)</span><br><span class="value">{{ $issued?->format('h:i:s A') }}</span></td>
</tr></table>

@if(!empty($images['image']) && ($receipt['layout'] ?? 'classic') === 'classic')
<div style="margin-top:10px"><img alt="" src="{{ $images['image'] }}" style="max-width:100%;max-height:110px" /></div>
@endif

<table width="100%" style="margin-top:12px" cellspacing="0"><tr>
    <td width="49%" class="card" style="vertical-align:top">
        <div class="label">المُصدِر</div>
        <div class="value">{{ $receipt['issuer_name'] }}</div>
        <div class="muted">منصة زاد سينك للأسر المنتجة</div>
    </td>
    <td width="2%"></td>
    <td width="49%" class="card" style="vertical-align:top">
        <div class="label">فاتورة إلى</div>
        <div class="value">{{ $receipt['store_name'] }}</div>
        <div class="muted">{{ $receipt['family_name'] }} · رقم الأسرة {{ $receipt['family_id'] }}</div>
    </td>
</tr></table>

<p style="margin:14px 0 4px">{{ $receipt['intro'] }}</p>
<table class="items">
    <thead><tr><th width="46%">البيان</th><th width="30%">فترة الاشتراك</th><th width="24%" class="num">المبلغ (ر.س)</th></tr></thead>
    <tbody>
        <tr>
            <td>{{ $planName }} — متجر {{ $receipt['store_name'] }}<br><span class="muted">{{ $receipt['duration_days'] }} يوم</span></td>
            <td>{{ $starts?->format('Y/m/d h:i A') }}<br><span class="muted">حتى {{ $ends?->format('Y/m/d h:i A') }}</span></td>
            <td class="num">{{ $sar($original) }}</td>
        </tr>
        @if($discount > 0)
        <tr>
            <td>خصم كود {{ $receipt['coupon_code'] ?? '' }}</td><td></td>
            <td class="num">- {{ $sar($discount) }}</td>
        </tr>
        @endif
        <tr class="total"><td>الإجمالي المدفوع</td><td></td><td class="num">{{ $sar($receipt['amount_halalas']) }} {{ $receipt['currency'] }}</td></tr>
    </tbody>
</table>

<table width="100%" style="margin-top:14px" cellspacing="0"><tr>
    <td width="62%" class="card" style="vertical-align:top">
        <div class="label">طريقة السداد</div><div class="value">{{ $method }}</div>
        <div class="label" style="margin-top:6px">وقت تأكيد السداد</div><div class="value">{{ $paid?->format('Y/m/d h:i:s A') }}</div>
        @if(($receipt['payment_mode'] ?? '') !== 'coupon')
        <div class="label" style="margin-top:6px">مرجع عملية ميسر</div><div class="mono">{{ $receipt['moyasar_payment_id'] }}</div>
        @endif
    </td>
    <td width="2%"></td>
    <td width="36%" style="text-align:center;vertical-align:middle">
        @if(!empty($images['stamp']))<img alt="stamp" src="{{ $images['stamp'] }}" style="width:120px;height:120px" />@endif
    </td>
</tr></table>

<table width="100%" style="margin-top:14px"><tr>
    <td width="20%" style="vertical-align:top">
        @if(class_exists(\Mpdf\QrCode\QrCode::class))
        <barcode code="{{ $verifyUrl }}" type="QR" size="0.9" error="M" disableborder="1" />
        @endif
    </td>
    <td width="80%" style="vertical-align:top">
        <div class="label">بصمة الفاتورة (SHA-256)</div>
        <div class="mono">{{ $fingerprint }}</div>
        <div class="muted" style="margin-top:6px">امسح الرمز للتحقق من صحة الفاتورة لدى زاد سينك. أي تعديل على بيانات الفاتورة يغيّر البصمة.</div>
    </td>
</tr></table>

<p class="muted" style="margin-top:14px;border-top:1px solid #e7e5e4;padding-top:8px">{{ $receipt['footer'] }}</p>
</body></html>
