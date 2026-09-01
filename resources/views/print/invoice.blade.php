@php
    $snap = $invoice->customer_snapshot ?? [];
    $pdf = $pdf ?? false;
    $draft = ($draft ?? false) || $invoice->isDraft();
    $eff = $invoice->effectiveStatus();
    $rate = $invoice->exchange_rate;
    $ilsOf = fn ($usd) => ($rate && (float) $rate > 0) ? \App\Support\Format::ils(\App\Support\Money::convertUsdToIls($usd, $rate)) : null;
    $paid = (float) $invoice->paid_usd_equivalent;
    $remaining = (float) $invoice->remaining_usd;
    $navy = '#17233f';
    $gold = '#e0a92e';
@endphp
<x-print-layout
    :title="'فاتورة '.$invoice->invoice_number"
    :pdf="$pdf"
    :watermark="$draft ? 'مسودة — DRAFT (غير صالحة كفاتورة رسمية)' : null">

    <style>
        .sa {
            color: {{ $navy }}; position: relative; overflow: hidden;
            font-family: {{ $pdf ? "'DejaVu Sans', sans-serif" : "'Tajawal','Segoe UI',Tahoma,sans-serif" }};
        }
        .sa .num { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; }
        .sa .gold { color: {{ $gold }}; }
        .sa svg { display: inline-block; vertical-align: middle; }

        .sa-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; }
        .sa-title { font-size:36px; font-weight:800; color:{{ $navy }}; margin:0; }
        .sa-numlabel { color:#9aa4b6; font-size:12px; margin:8px 0 0; }
        .sa-num { color:{{ $gold }}; font-weight:800; font-size:24px; letter-spacing:1px; margin:2px 0 0; }
        .sa-badge { display:inline-flex; align-items:center; gap:7px; background:{{ $navy }}; color:#fff; padding:8px 16px; border-radius:999px; font-size:12.5px; font-weight:600; }
        .sa-badge .dot { width:9px; height:9px; border-radius:999px; background:{{ $gold }}; display:inline-block; }

        .sa-word { font-weight:800; font-size:16px; color:{{ $navy }}; letter-spacing:1px; line-height:1; }
        .sa-word .a { color:{{ $gold }}; }
        .sa-word-sub { font-size:9px; color:#9aa4b6; letter-spacing:4px; margin-top:3px; }
        .sa-meta { display:flex; align-items:center; gap:8px; color:#5b6472; font-size:13px; margin-top:12px; }

        .sa-cards { display:flex; gap:16px; margin-top:20px; }
        .sa-card { flex:1; border:1px solid #e9ecf3; border-radius:14px; padding:16px 18px; }
        .sa-card-h { display:flex; align-items:center; justify-content:flex-end; gap:8px; margin:0 0 12px; font-size:15px; font-weight:700; color:{{ $navy }}; }
        .sa-r { display:flex; justify-content:space-between; font-size:12.5px; margin:7px 0; }
        .sa-r .k { color:#9aa4b6; }
        .sa-r .v { color:{{ $navy }}; font-weight:600; }

        table.sa-items { width:100%; border-collapse:separate; border-spacing:0; margin-top:22px; }
        table.sa-items thead th { background:{{ $navy }}; color:#fff; font-size:12.5px; font-weight:600; padding:13px 14px; text-align:right; }
        table.sa-items thead th:first-child { border-top-right-radius:12px; }
        table.sa-items thead th:last-child { border-top-left-radius:12px; }
        table.sa-items tbody td { padding:13px 14px; font-size:12.5px; border-bottom:1px solid #eef1f6; color:{{ $navy }}; }

        .sa-bottom { display:flex; gap:16px; margin-top:18px; align-items:stretch; }
        .sa-tot { flex:1; border:1px solid #e9ecf3; border-radius:14px; padding:16px 18px; }
        .sa-tot .l { display:flex; justify-content:space-between; font-size:13px; padding:5px 0; }
        .sa-tot .l .k { color:#7b8494; }
        .sa-tot .grand { border-top:2px solid #eef1f6; margin-top:8px; padding-top:11px; font-weight:800; font-size:16px; }

        .sa-side { flex:1; display:flex; flex-direction:column; gap:12px; }
        .sa-c { display:flex; align-items:center; justify-content:flex-end; gap:9px; font-size:12.5px; color:{{ $navy }}; margin:9px 0; }

        .sa-foot { display:flex; justify-content:space-between; align-items:center; margin-top:26px; padding-top:16px; border-top:1px solid #eef1f6; }
        .sa-soc { display:flex; gap:8px; }
        .sa-soc span { width:30px; height:30px; border-radius:999px; background:{{ $navy }}; display:inline-flex; align-items:center; justify-content:center; }
        .sa-corner { position:absolute; bottom:0; left:0; width:0; height:0; border-style:solid; border-width:0 0 70px 70px; border-color:transparent transparent {{ $gold }} transparent; opacity:.85; }
    </style>

    @php
        // Small inline gold line-icons (stroke = gold), print/PDF-safe simple paths.
        $ic = fn ($p, $c = null) => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="'.($c ?? $gold).'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>';
        $icCal = $ic('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>');
        $icClock = $ic('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>');
        $icDoc = $ic('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>');
        $icUser = $ic('<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>');
        $icNote = $ic('<path d="M4 4h16v12l-4 4H4z"/><path d="M14 20v-4h4"/>');
        $icPhone = $ic('<path d="M4 4h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 4 6a2 2 0 0 1 2-2z"/>');
        $icMail = $ic('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>');
        $icPin = $ic('<path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>');
    @endphp

    <div class="sa">
        {{-- ── Header ─────────────────────────────────────────────── --}}
        <div class="sa-head">
            {{-- RIGHT (RTL start): title + number + status --}}
            <div style="display:flex; align-items:flex-start; gap:14px;">
                {{-- dotted gold grid --}}
                <svg width="70" height="46" viewBox="0 0 70 46" style="margin-top:8px;">
                    @for ($r = 0; $r < 5; $r++)@for ($c = 0; $c < 8; $c++)<circle cx="{{ 4 + $c * 9 }}" cy="{{ 4 + $r * 9 }}" r="1.6" fill="{{ $gold }}" opacity="0.65"/>@endfor @endfor
                </svg>
                <div>
                    <h1 class="sa-title">فاتورة</h1>
                    <p class="sa-numlabel">رقم الفاتورة</p>
                    <p class="sa-num num">{{ $invoice->invoice_number }}</p>
                    <div style="margin-top:14px;"><span class="sa-badge"><span class="dot"></span>{{ $eff->label() }}</span></div>
                </div>
            </div>

            {{-- LEFT: logo + date + time --}}
            <div style="text-align:left;">
                <div style="display:flex; align-items:center; gap:11px; justify-content:flex-start;">
                    <svg width="52" height="52" viewBox="0 0 52 52">
                        <circle cx="33" cy="30" r="15" fill="{{ $gold }}"/>
                        <path d="M31 12c1 3 -1 5 -4 5 0 -3 2 -5 4 -5z" fill="{{ $navy }}"/>
                        <text x="12" y="38" font-size="30" font-weight="800" fill="{{ $navy }}" font-family="Arial, sans-serif">S</text>
                    </svg>
                    <div style="text-align:left;">
                        <div class="sa-word">{{ $company['name'] }}</div>
                        <div class="sa-word-sub">SUPER APPLE</div>
                    </div>
                </div>
                <div class="sa-meta">{!! $icCal !!}<span class="num">{{ $invoice->invoice_date->format('d/m/Y') }}</span></div>
                @if ($invoice->issued_at)
                    <div class="sa-meta">{!! $icClock !!}<span class="num">{{ $invoice->issued_at->format('h:i A') }}</span></div>
                @endif
            </div>
        </div>

        {{-- ── Info cards ─────────────────────────────────────────── --}}
        <div class="sa-cards">
            <div class="sa-card">
                <div class="sa-card-h">تفاصيل الفاتورة {!! $icDoc !!}</div>
                <div class="sa-r"><span class="k">رقم الفاتورة</span><span class="v num">{{ $invoice->invoice_number }}</span></div>
                <div class="sa-r"><span class="k">تاريخ الإصدار</span><span class="v num">{{ $invoice->invoice_date->format('d/m/Y') }}</span></div>
                <div class="sa-r"><span class="k">تاريخ الاستحقاق</span><span class="v num">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</span></div>
            </div>
            <div class="sa-card">
                <div class="sa-card-h">معلومات العميل {!! $icUser !!}</div>
                <div class="sa-r"><span class="k">الاسم</span><span class="v">{{ $snap['customer_name'] ?? $invoice->customer?->name ?? '—' }}</span></div>
                @php $custPhone = $snap['phone'] ?? $invoice->customer?->phone; $custAddr = $snap['address'] ?? $invoice->customer?->address; @endphp
                @if ($custPhone)<div class="sa-r"><span class="k">الهاتف</span><span class="v num">{{ $custPhone }}</span></div>@endif
                @if ($custAddr)<div class="sa-r"><span class="k">العنوان</span><span class="v">{{ $custAddr }}</span></div>@endif
            </div>
        </div>

        {{-- ── Items ──────────────────────────────────────────────── --}}
        <table class="sa-items">
            <thead>
                <tr><th>الوصف</th><th>الكمية</th><th>سعر الوحدة</th><th>الخصم</th><th>الضريبة</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr>
                        <td>{{ $item->item_name }}@if ($item->description)<br><span style="font-size:10px; color:#9aa4b6;">{{ $item->description }}</span>@endif</td>
                        <td class="num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                        <td class="num">${{ number_format((float) $item->unit_price_usd, 2) }}</td>
                        <td class="num">{{ (float) $item->discount_usd > 0 ? '$'.number_format((float) $item->discount_usd, 2) : '—' }}</td>
                        <td class="num">{{ (float) $item->tax_usd > 0 ? '$'.number_format((float) $item->tax_usd, 2) : '—' }}</td>
                        <td class="num">${{ number_format((float) $item->line_total_usd, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ── Totals + contact ───────────────────────────────────── --}}
        <div class="sa-bottom">
            <div class="sa-tot">
                <div class="l"><span class="k">المجموع الفرعي</span><span class="num">${{ number_format((float) $invoice->subtotal_usd, 2) }}</span></div>
                @if ((float) $invoice->discount_usd > 0)
                    <div class="l"><span class="k">الخصم</span><span class="num">−${{ number_format((float) $invoice->discount_usd, 2) }}</span></div>
                @endif
                <div class="l"><span class="k">الضريبة</span><span class="num">${{ number_format((float) $invoice->tax_usd, 2) }}</span></div>
                <div class="l grand"><span>الإجمالي</span><span class="num">${{ number_format((float) $invoice->total_usd, 2) }} USD</span></div>
                @if ($rate && (float) $rate > 0)
                    <div class="l" style="padding-top:0;"><span class="k" style="font-size:11px;">سعر الصرف</span><span class="num" style="font-size:11px; color:#9aa4b6;">1 USD = {{ $rate }} ILS</span></div>
                    <div class="l" style="padding-top:0;"><span class="k" style="font-size:11px;">ما يعادله</span><span class="num" style="font-size:11px; color:#9aa4b6;">≈ {{ $ilsOf($invoice->total_usd) }}</span></div>
                @endif
                <div class="l" style="color:#16a34a; font-weight:700;"><span>المدفوع</span><span class="num">${{ number_format($paid, 2) }}</span></div>
                <div class="l" style="color:{{ $remaining > 0 ? '#dc2626' : '#16a34a' }}; font-weight:700;"><span>المتبقي</span><span class="num">${{ number_format($remaining, 2) }}</span></div>
            </div>

            <div class="sa-side">
                @if ($invoice->terms || $company['invoice_footer'])
                    <div class="sa-card">
                        <div class="sa-card-h">ملاحظات {!! $icNote !!}</div>
                        @if ($invoice->terms)<p style="margin:0; font-size:12.5px; color:#5b6472;">{{ $invoice->terms }}</p>@endif
                        @if ($company['invoice_footer'])<p style="margin:6px 0 0; font-size:12px; color:#9aa4b6;">{{ $company['invoice_footer'] }}</p>@endif
                    </div>
                @endif
                <div class="sa-card">
                    <div class="sa-card-h">بيانات التواصل والدفع {!! $icPhone !!}</div>
                    @if ($company['phone'])<div class="sa-c">{!! $icPhone !!}<span class="num">{{ $company['phone'] }}</span></div>@endif
                    @if (($company['email'] ?? ''))<div class="sa-c">{!! $icMail !!}<span class="num">{{ $company['email'] }}</span></div>@endif
                    @if ($company['address'])<div class="sa-c">{!! $icPin !!}<span>{{ $company['address'] }}</span></div>@endif
                </div>
            </div>
        </div>

        {{-- ── Footer ─────────────────────────────────────────────── --}}
        <div class="sa-foot">
            {{-- decorative brand motif (not a scannable code) --}}
            <svg width="48" height="48" viewBox="0 0 48 48" aria-hidden="true">
                <rect x="1" y="1" width="14" height="14" rx="2" fill="none" stroke="{{ $navy }}" stroke-width="3"/>
                <rect x="33" y="1" width="14" height="14" rx="2" fill="none" stroke="{{ $navy }}" stroke-width="3"/>
                <rect x="1" y="33" width="14" height="14" rx="2" fill="none" stroke="{{ $navy }}" stroke-width="3"/>
                <rect x="6" y="6" width="4" height="4" fill="{{ $navy }}"/><rect x="38" y="6" width="4" height="4" fill="{{ $navy }}"/><rect x="6" y="38" width="4" height="4" fill="{{ $navy }}"/>
                <rect x="22" y="22" width="4" height="4" fill="{{ $gold }}"/><rect x="30" y="22" width="4" height="4" fill="{{ $navy }}"/><rect x="22" y="30" width="4" height="4" fill="{{ $navy }}"/><rect x="34" y="34" width="4" height="4" fill="{{ $gold }}"/>
            </svg>

            <div style="text-align:center; font-size:12.5px;">
                <div style="font-weight:700; color:{{ $navy }};">شكراً لتعاملكم مع {{ $company['name'] }} <span class="gold">♥</span></div>
                <div style="margin-top:4px; color:#9aa4b6;" class="num">
                    @if (($company['email'] ?? '')){{ $company['email'] }}@endif
                    @if (($company['email'] ?? '') && $company['phone']) &nbsp;•&nbsp; @endif
                    @if ($company['phone']){{ $company['phone'] }}@endif
                </div>
            </div>

            <div class="sa-soc">
                <span>{!! $ic('<path d="M6 9v7M6 6v.01M10 16v-4a2 2 0 0 1 4 0v4M10 12v4"/>', '#fff') !!}</span>
                <span>{!! $ic('<rect x="4" y="4" width="16" height="16" rx="5"/><circle cx="12" cy="12" r="3.5"/><path d="M17 7v.01"/>', '#fff') !!}</span>
                <span>{!! $ic('<path d="M14 8h2V5h-2a3 3 0 0 0-3 3v2H9v3h2v6h3v-6h2l1-3h-3V8a1 1 0 0 1 1-1z"/>', '#fff') !!}</span>
            </div>
        </div>

        @unless ($pdf)<div class="sa-corner"></div>@endunless
    </div>
</x-print-layout>
