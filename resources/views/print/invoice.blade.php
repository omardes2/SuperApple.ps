@php
    $snap = $invoice->customer_snapshot ?? [];
    $pdf = $pdf ?? false;
    $draft = ($draft ?? false) || $invoice->isDraft();
    $eff = $invoice->effectiveStatus();
    $rate = $invoice->exchange_rate;
    $ils = fn ($usd) => ($rate && (float) $rate > 0) ? \App\Support\Format::ils(\App\Support\Money::convertUsdToIls($usd, $rate)) : null;
    $paid = (float) $invoice->paid_usd_equivalent;
    $remaining = (float) $invoice->remaining_usd;
    $navy = '#1b2a4e';
    $gold = '#e2a72e';
@endphp
<x-print-layout
    :title="'فاتورة '.$invoice->invoice_number"
    :pdf="$pdf"
    :watermark="$draft ? 'مسودة — DRAFT (غير صالحة كفاتورة رسمية)' : null">

    <style>
        .sa-inv { color: {{ $navy }}; }
        .sa-inv .gold { color: {{ $gold }}; }
        .sa-head { display:flex; justify-content:space-between; align-items:flex-start; }
        .sa-title { font-size:34px; font-weight:800; color:{{ $navy }}; margin:0; letter-spacing:1px; }
        .sa-num-label { color:#94a3b8; font-size:12px; margin:6px 0 0; }
        .sa-num { color:{{ $gold }}; font-weight:800; font-size:22px; margin:2px 0 0; letter-spacing:1px; }
        .sa-logo-word { font-weight:800; font-size:15px; color:{{ $navy }}; letter-spacing:2px; }
        .sa-logo-sub { font-size:9px; color:#94a3b8; letter-spacing:3px; }
        .sa-badge { display:inline-flex; align-items:center; gap:6px; background:{{ $navy }}; color:#fff; padding:6px 14px; border-radius:999px; font-size:12px; font-weight:600; }
        .sa-badge .dot { width:8px; height:8px; border-radius:999px; background:{{ $gold }}; display:inline-block; }
        .sa-meta { color:#475569; font-size:12px; margin:10px 0 0; }
        .sa-cards { display:flex; gap:14px; margin-top:22px; }
        .sa-card { flex:1; border:1px solid #e6e9f0; border-radius:12px; padding:14px 16px; }
        .sa-card h3 { margin:0 0 10px; font-size:14px; color:{{ $navy }}; font-weight:700; text-align:left; }
        .sa-row { display:flex; justify-content:space-between; font-size:12px; margin:5px 0; }
        .sa-row .k { color:#94a3b8; }
        .sa-row .v { color:{{ $navy }}; font-weight:600; }
        table.sa-items { width:100%; border-collapse:collapse; margin-top:22px; border-radius:12px; overflow:hidden; }
        table.sa-items thead th { background:{{ $navy }}; color:#fff; font-size:12px; font-weight:600; padding:11px 12px; text-align:right; }
        table.sa-items tbody td { padding:11px 12px; font-size:12px; border-bottom:1px solid #eef1f6; color:{{ $navy }}; }
        .sa-bottom { display:flex; gap:14px; margin-top:18px; align-items:flex-start; }
        .sa-tot { flex:1; border:1px solid #e6e9f0; border-radius:12px; padding:14px 16px; }
        .sa-tot .line { display:flex; justify-content:space-between; font-size:13px; padding:5px 0; }
        .sa-tot .line.grand { border-top:2px solid #e6e9f0; margin-top:6px; padding-top:10px; font-weight:800; color:{{ $navy }}; font-size:15px; }
        .sa-tot .k { color:#64748b; }
        .sa-tot .num { direction:ltr; font-variant-numeric:tabular-nums; }
        .sa-side { flex:1; }
        .sa-contact .c { display:flex; align-items:center; gap:8px; font-size:12px; color:{{ $navy }}; margin:8px 0; direction:rtl; }
        .sa-foot { display:flex; justify-content:space-between; align-items:center; margin-top:26px; padding-top:14px; border-top:1px solid #eef1f6; }
        .sa-ico { color:{{ $gold }}; flex:none; }
        .num { direction:ltr; text-align:left; font-variant-numeric:tabular-nums; }
    </style>

    <div class="sa-inv">
        {{-- Header --}}
        <div class="sa-head">
            <div>
                <h1 class="sa-title">فاتورة</h1>
                <p class="sa-num-label">رقم الفاتورة</p>
                <p class="sa-num num">{{ $invoice->invoice_number }}</p>
                <div style="margin-top:12px;"><span class="sa-badge"><span class="dot"></span>{{ $eff->label() }}</span></div>
            </div>
            <div style="text-align:left;">
                <div style="display:flex; align-items:center; gap:10px; justify-content:flex-start;">
                    <div style="width:46px; height:46px; border-radius:12px; background:{{ $gold }}; color:{{ $navy }}; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px;">SA</div>
                    <div style="text-align:left;">
                        <div class="sa-logo-word">{{ $company['name'] }}</div>
                        <div class="sa-logo-sub">SUPER APPLE</div>
                    </div>
                </div>
                <p class="sa-meta num">📅 {{ $invoice->invoice_date->format('d/m/Y') }}</p>
                @if ($invoice->issued_at)<p class="sa-meta num">🕐 {{ $invoice->issued_at->format('h:i A') }}</p>@endif
            </div>
        </div>

        {{-- Info cards --}}
        <div class="sa-cards">
            <div class="sa-card">
                <h3>تفاصيل الفاتورة</h3>
                <div class="sa-row"><span class="k">رقم الفاتورة</span><span class="v num">{{ $invoice->invoice_number }}</span></div>
                <div class="sa-row"><span class="k">تاريخ الإصدار</span><span class="v num">{{ $invoice->invoice_date->format('d/m/Y') }}</span></div>
                <div class="sa-row"><span class="k">تاريخ الاستحقاق</span><span class="v num">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</span></div>
            </div>
            <div class="sa-card">
                <h3>معلومات العميل</h3>
                <div class="sa-row"><span class="k">الاسم</span><span class="v">{{ $snap['customer_name'] ?? $invoice->customer?->name ?? '—' }}</span></div>
                @php $custPhone = $snap['phone'] ?? $invoice->customer?->phone; @endphp
                @if ($custPhone)<div class="sa-row"><span class="k">الهاتف</span><span class="v num">{{ $custPhone }}</span></div>@endif
                @php $custAddr = $snap['address'] ?? $invoice->customer?->address; @endphp
                @if ($custAddr)<div class="sa-row"><span class="k">العنوان</span><span class="v">{{ $custAddr }}</span></div>@endif
            </div>
        </div>

        {{-- Items --}}
        <table class="sa-items">
            <thead>
                <tr>
                    <th>الوصف</th><th>الكمية</th><th>سعر الوحدة</th><th>الخصم</th><th>الضريبة</th><th>الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr>
                        <td>{{ $item->item_name }}@if ($item->description)<br><span style="font-size:10px; color:#94a3b8;">{{ $item->description }}</span>@endif</td>
                        <td class="num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                        <td class="num">${{ number_format((float) $item->unit_price_usd, 2) }}</td>
                        <td class="num">{{ (float) $item->discount_usd > 0 ? '$'.number_format((float) $item->discount_usd, 2) : '—' }}</td>
                        <td class="num">{{ (float) $item->tax_usd > 0 ? '$'.number_format((float) $item->tax_usd, 2) : '—' }}</td>
                        <td class="num">${{ number_format((float) $item->line_total_usd, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Bottom: totals + contact --}}
        <div class="sa-bottom">
            <div class="sa-tot">
                <div class="line"><span class="k">المجموع الفرعي</span><span class="num">${{ number_format((float) $invoice->subtotal_usd, 2) }}</span></div>
                @if ((float) $invoice->discount_usd > 0)
                    <div class="line"><span class="k">الخصم</span><span class="num">−${{ number_format((float) $invoice->discount_usd, 2) }}</span></div>
                @endif
                <div class="line"><span class="k">الضريبة</span><span class="num">${{ number_format((float) $invoice->tax_usd, 2) }}</span></div>
                <div class="line grand"><span>الإجمالي</span><span class="num">${{ number_format((float) $invoice->total_usd, 2) }} USD</span></div>
                @if ($rate && (float) $rate > 0)
                    <div class="line" style="padding-top:0;"><span class="k" style="font-size:11px;">سعر الصرف</span><span class="num" style="font-size:11px; color:#94a3b8;">1 USD = {{ $rate }} ILS</span></div>
                    <div class="line" style="padding-top:0;"><span class="k" style="font-size:11px;">ما يعادله</span><span class="num" style="font-size:11px; color:#94a3b8;">≈ {{ $ils($invoice->total_usd) }}</span></div>
                @endif
                <div class="line" style="color:#16a34a; font-weight:700;"><span>المدفوع</span><span class="num">${{ number_format($paid, 2) }}</span></div>
                <div class="line" style="color:{{ $remaining > 0 ? '#dc2626' : '#16a34a' }}; font-weight:700;"><span>المتبقي</span><span class="num">${{ number_format($remaining, 2) }}</span></div>
            </div>

            <div class="sa-side">
                @if ($invoice->terms || $company['invoice_footer'])
                    <div class="sa-card" style="margin-bottom:12px;">
                        <h3>ملاحظات</h3>
                        @if ($invoice->terms)<p style="margin:0; font-size:12px; color:#475569;">{{ $invoice->terms }}</p>@endif
                        @if ($company['invoice_footer'])<p style="margin:6px 0 0; font-size:12px; color:#94a3b8;">{{ $company['invoice_footer'] }}</p>@endif
                    </div>
                @endif
                <div class="sa-card sa-contact">
                    <h3>بيانات التواصل والدفع</h3>
                    @if ($company['phone'])<div class="c"><span class="sa-ico">📞</span><span class="num">{{ $company['phone'] }}</span></div>@endif
                    @if (($company['email'] ?? ''))<div class="c"><span class="sa-ico">✉</span><span class="num">{{ $company['email'] }}</span></div>@endif
                    @if ($company['address'])<div class="c"><span class="sa-ico">📍</span><span>{{ $company['address'] }}</span></div>@endif
                </div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="sa-foot">
            <div style="font-size:11px; color:#94a3b8;" class="num">{{ $invoice->invoice_number }}</div>
            <div style="text-align:center; font-size:12px; color:{{ $navy }};">
                <div style="font-weight:700;">شكراً لتعاملكم مع {{ $company['name'] }} 🧡</div>
                <div style="margin-top:4px; color:#94a3b8;" class="num">
                    @if (($company['email'] ?? '')){{ $company['email'] }}@endif
                    @if (($company['email'] ?? '') && $company['phone']) · @endif
                    @if ($company['phone']){{ $company['phone'] }}@endif
                </div>
            </div>
            <div style="font-size:11px; color:{{ $gold }};">●●●</div>
        </div>
    </div>
</x-print-layout>
