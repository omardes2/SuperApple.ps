@php
    $snap = $invoice->customer_snapshot ?? [];
    $pdf = $pdf ?? false;
    $draft = ($draft ?? false) || $invoice->isDraft();
@endphp
<x-print-layout
    :title="'فاتورة '.$invoice->invoice_number"
    :pdf="$pdf"
    :watermark="$draft ? 'مسودة — DRAFT (غير صالحة كفاتورة رسمية)' : null">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #1f47f5; padding-bottom:14px;">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <h1>{{ $company['name'] }}</h1>
                <p class="muted" style="margin:4px 0 0; font-size:12px;">
                    {{ $company['address'] }}
                    @if ($company['phone']) · هاتف: <span class="num">{{ $company['phone'] }}</span>@endif
                    @if ($company['tax_number']) · ر.ض: <span class="num">{{ $company['tax_number'] }}</span>@endif
                </p>
            </div>
        </div>
        <div style="text-align:left;">
            <h1 style="color:#1f47f5;">فاتورة</h1>
            <p class="num muted" style="margin:4px 0 0;">{{ $invoice->invoice_number }}</p>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-top:18px; font-size:13px;">
        <div>
            <p class="muted" style="margin:0 0 4px;">إلى:</p>
            <p style="margin:0; font-weight:600;">{{ $snap['customer_name'] ?? $invoice->customer->name }}</p>
            @if (($snap['contact_person'] ?? $invoice->customer->contact_person))<p style="margin:2px 0;">{{ $snap['contact_person'] ?? $invoice->customer->contact_person }}</p>@endif
            <p class="num" style="margin:2px 0;">{{ $snap['phone'] ?? $invoice->customer->phone }}</p>
            @if (($snap['address'] ?? $invoice->customer->address))<p style="margin:2px 0;">{{ $snap['address'] ?? $invoice->customer->address }}</p>@endif
        </div>
        <div style="text-align:left;">
            <p style="margin:0 0 4px;"><span class="muted">تاريخ الفاتورة:</span> <span class="num">{{ $invoice->invoice_date->format('Y-m-d') }}</span></p>
            <p style="margin:0 0 4px;"><span class="muted">تاريخ الاستحقاق:</span> <span class="num">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</span></p>
            <p style="margin:0;"><span class="muted">الحالة:</span> {{ $invoice->effectiveStatus()->label() }}</p>
        </div>
    </div>

    <table class="items" style="margin-top:18px;">
        <thead>
            <tr><th>البند</th><th>الكمية</th><th>السعر (USD)</th><th>الخصم</th><th>الضريبة</th><th>الإجمالي (USD)</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->item_name }}@if ($item->description)<br><span class="muted" style="font-size:11px;">{{ $item->description }}</span>@endif</td>
                    <td class="num">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                    <td class="num">${{ number_format((float) $item->unit_price_usd, 2) }}</td>
                    <td class="num">${{ number_format((float) $item->discount_usd, 2) }}</td>
                    <td class="num">${{ number_format((float) $item->tax_usd, 2) }}</td>
                    <td class="num">${{ number_format((float) $item->line_total_usd, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="display:flex; justify-content:flex-start; margin-top:14px;">
        <table class="totals" style="width:300px;">
            <tr><td class="muted">المجموع الفرعي</td><td class="num">${{ number_format((float) $invoice->subtotal_usd, 2) }}</td></tr>
            <tr><td class="muted">الخصم</td><td class="num">-${{ number_format((float) $invoice->discount_usd, 2) }}</td></tr>
            <tr><td class="muted">الضريبة</td><td class="num">${{ number_format((float) $invoice->tax_usd, 2) }}</td></tr>
            <tr style="border-top:2px solid #1f47f5;"><td style="font-weight:700;">الإجمالي</td><td class="num" style="font-weight:700;">${{ number_format((float) $invoice->total_usd, 2) }} USD</td></tr>
            @if ($invoice->exchange_rate)
                <tr><td class="muted" style="font-size:12px;">سعر الصرف عند الإصدار</td><td class="num" style="font-size:12px;">1 USD = {{ $invoice->exchange_rate }} ILS</td></tr>
                <tr><td class="muted" style="font-size:12px;">ما يعادله</td><td class="num" style="font-size:12px;">{{ number_format((float) $invoice->total_ils_at_issue, 2) }} ILS</td></tr>
            @endif
        </table>
    </div>

    <div style="margin-top:26px; border-top:1px solid #e2e8f0; padding-top:12px; font-size:12px;" class="muted">
        <p style="margin:0 0 6px; font-weight:600; color:#334155;">القيمة الأساسية المستحقة لهذه الفاتورة هي بالدولار الأمريكي.</p>
        @if ($invoice->terms)<p style="margin:0 0 6px;">{{ $invoice->terms }}</p>@endif
        @if ($company['invoice_footer'])<p style="margin:0;">{{ $company['invoice_footer'] }}</p>@endif
    </div>
</x-print-layout>
