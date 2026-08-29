@php $snap = $quotation->customer_snapshot ?? []; @endphp
<x-print-layout :title="'عرض سعر '.$quotation->quotation_number">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #1f47f5; padding-bottom:14px;">
        <div class="brand">
            <div class="logo">S</div>
            <div>
                <h1>{{ $company['name'] }}</h1>
                <p class="muted" style="margin:4px 0 0; font-size:12px;">
                    {{ $company['address'] }}
                    @if ($company['phone']) · هاتف: <span class="num">{{ $company['phone'] }}</span>@endif
                </p>
            </div>
        </div>
        <div style="text-align:left;">
            <h1 style="color:#1f47f5;">عرض سعر</h1>
            <p class="num muted" style="margin:4px 0 0;">{{ $quotation->quotation_number }}</p>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-top:18px; font-size:13px;">
        <div>
            <p class="muted" style="margin:0 0 4px;">إلى:</p>
            <p style="margin:0; font-weight:600;">{{ $snap['customer_name'] ?? $quotation->customer->name }}</p>
            <p class="num" style="margin:2px 0;">{{ $snap['phone'] ?? $quotation->customer->phone }}</p>
        </div>
        <div style="text-align:left;">
            <p style="margin:0 0 4px;"><span class="muted">التاريخ:</span> <span class="num">{{ $quotation->quotation_date->format('Y-m-d') }}</span></p>
            <p style="margin:0 0 4px;"><span class="muted">صالح حتى:</span> <span class="num">{{ $quotation->valid_until?->format('Y-m-d') ?? '—' }}</span></p>
            <p style="margin:0;"><span class="muted">الحالة:</span> {{ $quotation->effectiveStatus()->label() }}</p>
        </div>
    </div>

    <table class="items" style="margin-top:18px;">
        <thead>
            <tr><th>البند</th><th>الكمية</th><th>السعر (USD)</th><th>الخصم</th><th>الضريبة</th><th>الإجمالي (USD)</th></tr>
        </thead>
        <tbody>
            @foreach ($quotation->items as $item)
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
            <tr><td class="muted">المجموع الفرعي</td><td class="num">${{ number_format((float) $quotation->subtotal_usd, 2) }}</td></tr>
            <tr><td class="muted">الخصم</td><td class="num">-${{ number_format((float) $quotation->discount_usd, 2) }}</td></tr>
            <tr><td class="muted">الضريبة</td><td class="num">${{ number_format((float) $quotation->tax_usd, 2) }}</td></tr>
            <tr style="border-top:2px solid #1f47f5;"><td style="font-weight:700;">الإجمالي</td><td class="num" style="font-weight:700;">${{ number_format((float) $quotation->total_usd, 2) }} USD</td></tr>
        </table>
    </div>

    @if ($quotation->terms)
        <div style="margin-top:26px; border-top:1px solid #e2e8f0; padding-top:12px; font-size:12px;" class="muted">{{ $quotation->terms }}</div>
    @endif
</x-print-layout>
