@php
    $snap = $invoice->customer_snapshot ?? [];
    $pdf = $pdf ?? false;
    $draft = ($draft ?? false) || $invoice->isDraft();
    $paid = (float) $invoice->paid_usd_equivalent;
    $remaining = (float) $invoice->remaining_usd;
    $hasDiscount = (float) $invoice->discount_usd > 0;
    $hasTax = (float) $invoice->tax_usd > 0;
    // The customer's contact number: prefer phone, fall back to WhatsApp,
    // from the historical snapshot first then the live customer record.
    $custPhone = ($snap['phone'] ?? null) ?: ($snap['whatsapp_number'] ?? null)
        ?: $invoice->customer?->phone ?: $invoice->customer?->whatsapp_number;
    $navy = '#17233F';
    $gold = '#D7A32D';
    $gray = '#F5F6F8';
    $line = '#E8EAEE';
    $ink = '#1F2937';
    $mut = '#8A93A2';
    $usd = fn ($v) => '$'.number_format((float) $v, 2);
    // Small gold line-icons for the footer contact row (print/PDF-safe paths).
    $ic = fn ($p) => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="'.$gold.'" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>';
    $icPhone = $ic('<path d="M4 4h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 4 6a2 2 0 0 1 2-2z"/>');
    $icMail = $ic('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>');
    $icPin = $ic('<path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>');
@endphp
<x-print-layout
    :title="'فاتورة '.$invoice->invoice_number"
    :pdf="$pdf"
    :watermark="$draft ? 'مسودة — DRAFT (غير صالحة كفاتورة رسمية)' : null">

    <style>
        @unless ($pdf)
        /* Cairo is self-hosted from this server (public/fonts/cairo, sourced from
           @fontsource/cairo) — no external request when the invoice is opened.
           @font-face is honoured inside a body <style> (unlike @import). The
           arabic/latin subsets are split by unicode-range. Weights 400/600/700. */
        @font-face { font-family: 'Cairo'; font-style: normal; font-weight: 400; font-display: swap; src: url('{{ asset('fonts/cairo/cairo-latin-400-normal.woff2') }}') format('woff2'); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+2000-206F, U+20A0-20CF, U+2212; }
        @font-face { font-family: 'Cairo'; font-style: normal; font-weight: 600; font-display: swap; src: url('{{ asset('fonts/cairo/cairo-latin-600-normal.woff2') }}') format('woff2'); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+2000-206F, U+20A0-20CF, U+2212; }
        @font-face { font-family: 'Cairo'; font-style: normal; font-weight: 700; font-display: swap; src: url('{{ asset('fonts/cairo/cairo-latin-700-normal.woff2') }}') format('woff2'); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+2000-206F, U+20A0-20CF, U+2212; }
        @font-face { font-family: 'Cairo'; font-style: normal; font-weight: 400; font-display: swap; src: url('{{ asset('fonts/cairo/cairo-arabic-400-normal.woff2') }}') format('woff2'); unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+FB50-FDFF, U+FE70-FEFF; }
        @font-face { font-family: 'Cairo'; font-style: normal; font-weight: 600; font-display: swap; src: url('{{ asset('fonts/cairo/cairo-arabic-600-normal.woff2') }}') format('woff2'); unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+FB50-FDFF, U+FE70-FEFF; }
        @font-face { font-family: 'Cairo'; font-style: normal; font-weight: 700; font-display: swap; src: url('{{ asset('fonts/cairo/cairo-arabic-700-normal.woff2') }}') format('woff2'); unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+FB50-FDFF, U+FE70-FEFF; }

        /* Pin the printed page box so browser printing is consistent regardless
           of the user's margin setting; the sheet's own 12mm padding is the
           margin. (dompdf keeps its setPaper('a4') box — this is browser-only.) */
        @page { size: A4; margin: 0; }
        @endunless

        .inv {
            color: {{ $ink }};
            /* PDF path (dompdf, offline) uses the project's embedded DejaVu Sans;
               the browser uses the self-hosted Cairo above, with local Arabic
               system fonts as the only fallback. Raw (unescaped) echo is required:
               an escaped echo inside style turns the quotes into entities and
               voids the whole declaration. */
            font-family: {!! $pdf ? "'DejaVu Sans', sans-serif" : "'Cairo','Tahoma','Arial',sans-serif" !!};
            /* Weight scale: 400 body · 600 small headings · 700 big titles. */
            font-weight: 400;
            font-size: 12px; line-height: 1.5;
        }
        .inv * { box-sizing: border-box; }
        .inv .num { direction: ltr; unicode-bidi: embed; font-variant-numeric: tabular-nums; }
        .inv table { width: 100%; border-collapse: collapse; }

        /* Header — compact */
        .inv-head { width: 100%; margin-bottom: 18px; }
        .inv-head td { vertical-align: middle; }
        .inv-brand { display: flex; align-items: center; gap: 10px; }
        .inv-word { font-size: 17px; font-weight: 700; color: {{ $navy }}; line-height: 1; }
        .inv-word small { display: block; font-size: 8px; font-weight: 600; letter-spacing: 3px; color: {{ $gold }}; margin-top: 4px; }
        .inv-doc { text-align: left; }
        .inv-doc-t { font-size: 22px; font-weight: 700; color: {{ $navy }}; line-height: 1; }
        .inv-doc-m { font-size: 11px; color: {{ $mut }}; margin-top: 7px; }
        .inv-doc-m b { color: {{ $navy }}; font-weight: 600; }

        .inv-rule { height: 2px; background: {{ $navy }}; border: 0; margin: 0 0 16px; }

        /* Meta cards — very small, low height */
        .inv-meta { margin-bottom: 18px; }
        .inv-meta td { width: 50%; vertical-align: top; }
        .inv-meta td:first-child { padding-left: 8px; }
        .inv-meta td:last-child { padding-right: 8px; }
        .inv-cardh { font-size: 10px; font-weight: 600; color: {{ $gold }}; letter-spacing: .5px; margin-bottom: 6px; text-transform: uppercase; }
        .inv-card { background: {{ $gray }}; border: 1px solid {{ $line }}; border-radius: 8px; padding: 10px 12px; }
        .inv-kv { display: flex; justify-content: space-between; gap: 10px; padding: 2px 0; font-size: 11.5px; }
        .inv-kv .k { color: {{ $mut }}; }
        .inv-kv .v { color: {{ $navy }}; font-weight: 600; text-align: left; }

        /* Items — the hero */
        .inv-items { margin-bottom: 16px; }
        .inv-items thead th {
            background: {{ $navy }}; color: #fff; font-weight: 600; font-size: 11px;
            padding: 10px 10px; text-align: right;
        }
        .inv-items thead th.n { text-align: left; }
        .inv-items thead th:first-child { border-top-right-radius: 6px; }
        .inv-items thead th:last-child { border-top-left-radius: 6px; }
        .inv-items tbody td { padding: 8px 10px; font-size: 11.5px; border-bottom: 1px solid {{ $line }}; color: {{ $ink }}; vertical-align: top; }
        .inv-items tbody tr:nth-child(even) td { background: #FBFBFC; }
        .inv-items td.n { text-align: left; color: {{ $navy }}; }
        .inv-items .desc { font-weight: 600; color: {{ $navy }}; }
        .inv-items .desc small { display: block; font-weight: 400; font-size: 10px; color: {{ $mut }}; margin-top: 2px; }
        .inv-items thead { display: table-header-group; }
        .inv-items tr { page-break-inside: avoid; }

        /* Totals — small & elegant, aligned to the end (left in RTL) */
        .inv-foot { page-break-inside: avoid; }
        .inv-foot td { vertical-align: top; }
        .inv-notes { font-size: 11px; color: {{ $mut }}; padding-left: 16px; }
        .inv-notes .h { font-size: 10px; font-weight: 600; color: {{ $gold }}; letter-spacing: .5px; margin-bottom: 5px; text-transform: uppercase; }
        .inv-tot { width: 46%; }
        .inv-tot table { background: {{ $gray }}; border: 1px solid {{ $line }}; border-radius: 8px; overflow: hidden; }
        .inv-tot td { padding: 6px 12px; font-size: 12px; }
        .inv-tot td.k { color: {{ $mut }}; }
        .inv-tot td.v { text-align: left; color: {{ $navy }}; font-weight: 600; }
        .inv-tot tr.grand td { background: {{ $navy }}; color: #fff; font-size: 13.5px; font-weight: 700; padding: 9px 12px; }
        .inv-tot tr.grand td.v { color: {{ $gold }}; }
        .inv-tot tr.pay td { padding-top: 8px; }
        .inv-tot tr.pay td.v { color: #15803D; }
        .inv-tot tr.rem td.v { color: {{ $remaining > 0 ? '#B91C1C' : '#15803D' }}; }
        .inv-tot tr.rem td { font-weight: 600; }

        /* Footer — tiny: slogan + centered contact row with icons */
        .inv-bottom { margin-top: 20px; padding-top: 12px; border-top: 1px solid {{ $line }}; text-align: center; font-size: 11px; color: {{ $mut }}; page-break-inside: avoid; }
        .inv-bottom .ty { color: {{ $navy }}; font-weight: 600; font-size: 12.5px; margin-bottom: 8px; }
        .inv-contact { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 6px 22px; }
        .inv-contact .ci { display: inline-flex; align-items: center; gap: 6px; color: {{ $ink }}; }
        .inv-contact .ci svg { flex: none; }

        @if ($pdf)
        /* dompdf honours position:fixed as a page-bottom footer; small gap. */
        .inv { padding-bottom: 34mm; }
        .inv-bottom { position: fixed; left: 0; right: 0; bottom: 15mm; margin: 0; background: #fff; }
        @else
        /* Browser on screen: pin the footer to the bottom of the A4 sheet
           (the print layout gives .sheet position:relative + min-height:297mm),
           with a small bottom gap. */
        @media screen {
            .inv { padding-bottom: 34mm; }
            .inv-bottom { position: absolute; left: 0; right: 0; bottom: 15mm; margin: 0; }
        }
        /* Browser print: pin to the bottom of every printed page, small gap. */
        @media print {
            .inv { padding-bottom: 34mm; }
            .inv-bottom { position: fixed; left: 12mm; right: 12mm; bottom: 15mm; margin: 0; background: #fff; }
        }
        @endif
    </style>

    <div class="inv">
        {{-- ── Header (compact) ───────────────────────────────── --}}
        <table class="inv-head">
            <tr>
                <td>
                    @if (! empty($company['logo_data_uri']))
                        <img src="{{ $company['logo_data_uri'] }}" alt="{{ $company['name'] }}" style="max-height:60px; max-width:240px; object-fit:contain;">
                    @else
                        <div class="inv-brand">
                            <svg width="34" height="34" viewBox="0 0 34 34" aria-hidden="true">
                                <circle cx="19" cy="20" r="11" fill="{{ $gold }}"/>
                                <path d="M18 5c.8 2.2-.7 3.9-2.9 3.9C15.1 6.7 16.6 5 18 5z" fill="{{ $navy }}"/>
                                <circle cx="12.5" cy="20" r="7.5" fill="{{ $navy }}"/>
                            </svg>
                            <div class="inv-word">{{ $company['name'] }}<small>SUPER APPLE</small></div>
                        </div>
                    @endif
                </td>
                <td class="inv-doc">
                    <div class="inv-doc-t">فاتورة</div>
                    <div class="inv-doc-m">رقم الفاتورة <b class="num">{{ $invoice->invoice_number }}</b></div>
                    <div class="inv-doc-m">تاريخ الإصدار <b class="num">{{ $invoice->invoice_date->format('d/m/Y') }}</b></div>
                </td>
            </tr>
        </table>
        <hr class="inv-rule">

        {{-- ── Meta cards (small) ─────────────────────────────── --}}
        <table class="inv-meta">
            <tr>
                <td>
                    <div class="inv-cardh">معلومات العميل</div>
                    <div class="inv-card">
                        <div class="inv-kv"><span class="k">الاسم</span><span class="v">{{ $snap['customer_name'] ?? $invoice->customer?->name ?? '—' }}</span></div>
                        @if ($custPhone)<div class="inv-kv"><span class="k">الهاتف</span><span class="v num">{{ $custPhone }}</span></div>@endif
                    </div>
                </td>
                <td>
                    <div class="inv-cardh">تفاصيل الفاتورة</div>
                    <div class="inv-card">
                        <div class="inv-kv"><span class="k">رقم الفاتورة</span><span class="v num">{{ $invoice->invoice_number }}</span></div>
                        <div class="inv-kv"><span class="k">تاريخ الإصدار</span><span class="v num">{{ $invoice->invoice_date->format('d/m/Y') }}</span></div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- ── Items (hero) ───────────────────────────────────── --}}
        <table class="inv-items">
            <thead>
                <tr>
                    <th style="width:40%;">الوصف</th>
                    <th class="n" style="width:8%;">الكمية</th>
                    <th class="n" style="width:15%;">سعر الوحدة</th>
                    <th class="n" style="width:11%;">الخصم</th>
                    <th class="n" style="width:11%;">الضريبة</th>
                    <th class="n" style="width:15%;">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr>
                        <td class="desc">{{ $item->item_name }}@if ($item->description)<small>{{ $item->description }}</small>@endif</td>
                        <td class="n num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                        <td class="n num">{{ $usd($item->unit_price_usd) }}</td>
                        <td class="n num">{{ (float) $item->discount_usd > 0 ? $usd($item->discount_usd) : '—' }}</td>
                        <td class="n num">{{ (float) $item->tax_usd > 0 ? $usd($item->tax_usd) : '—' }}</td>
                        <td class="n num">{{ $usd($item->line_total_usd) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ── Notes + Totals ─────────────────────────────────── --}}
        <table class="inv-foot">
            <tr>
                <td class="inv-notes">
                    @if ($invoice->terms || $company['invoice_footer'])
                        <div class="h">ملاحظات</div>
                        @if ($invoice->terms)<div>{{ $invoice->terms }}</div>@endif
                        @if ($company['invoice_footer'])<div style="margin-top:4px;">{{ $company['invoice_footer'] }}</div>@endif
                    @endif
                </td>
                <td class="inv-tot">
                    <table>
                        <tr><td class="k">المجموع الفرعي</td><td class="v num">{{ $usd($invoice->subtotal_usd) }}</td></tr>
                        @if ($hasDiscount)
                            <tr><td class="k">الخصم</td><td class="v num">−{{ $usd($invoice->discount_usd) }}</td></tr>
                        @endif
                        <tr><td class="k">الضريبة</td><td class="v num">{{ $usd($invoice->tax_usd) }}</td></tr>
                        <tr class="grand"><td>الإجمالي</td><td class="v num">{{ $usd($invoice->total_usd) }} USD</td></tr>
                        <tr class="pay"><td class="k">المدفوع</td><td class="v num">{{ $usd($paid) }}</td></tr>
                        <tr class="rem"><td class="k">المتبقي</td><td class="v num">{{ $usd($remaining) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- ── Footer (tiny, contact only) ────────────────────── --}}
        <div class="inv-bottom">
            <div class="ty">نحن نصنع الإعلان … نحن نصنع النجاح</div>
            <div class="inv-contact">
                @if ($company['phone'])<span class="ci">{!! $icPhone !!}<span class="num">{{ $company['phone'] }}</span></span>@endif
                @if (($company['email'] ?? ''))<span class="ci">{!! $icMail !!}<span class="num">{{ $company['email'] }}</span></span>@endif
                @if ($company['address'])<span class="ci">{!! $icPin !!}<span>{{ $company['address'] }}</span></span>@endif
            </div>
        </div>
    </div>
</x-print-layout>
