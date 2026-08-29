<x-print-layout :title="'إيصال '.$payment->payment_number">
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
            <h1 style="color:#1f47f5;">إيصال قبض</h1>
            <p class="num muted" style="margin:4px 0 0;">{{ $payment->payment_number }}</p>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-top:18px; font-size:13px;">
        <div>
            <p class="muted" style="margin:0 0 4px;">استلمنا من:</p>
            <p style="margin:0; font-weight:600;">{{ $payment->customer->name }}</p>
            @if ($payment->customer->phone)<p class="num" style="margin:2px 0;">{{ $payment->customer->phone }}</p>@endif
        </div>
        <div style="text-align:left;">
            <p style="margin:0 0 4px;"><span class="muted">تاريخ القبض:</span> <span class="num">{{ $payment->payment_date->format('Y-m-d') }}</span></p>
            <p style="margin:0 0 4px;"><span class="muted">طريقة الدفع:</span> {{ $payment->payment_method->label() }}</p>
            @if ($payment->reference_number)<p style="margin:0;"><span class="muted">المرجع:</span> <span class="num">{{ $payment->reference_number }}</span></p>@endif
        </div>
    </div>

    <div style="margin-top:18px; border:1px solid #e2e8f0; border-radius:8px; padding:16px; background:#f8fafc;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="muted">المبلغ المستلم</span>
            <span class="num" style="font-size:22px; font-weight:700;">{{ number_format((float) $payment->payment_amount, 2) }} {{ $payment->payment_currency->value }}</span>
        </div>
        @if ($payment->payment_currency->value === 'ILS')
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; font-size:12px;" class="muted">
                <span>سعر الصرف: 1 USD = {{ $payment->exchange_rate }} ILS</span>
                <span class="num">ما يعادله ${{ number_format((float) $payment->usd_equivalent, 2) }} USD</span>
            </div>
        @endif
    </div>

    @if ($payment->activeAllocations->isNotEmpty())
        <table class="items" style="margin-top:18px;">
            <thead>
                <tr><th>الفاتورة</th><th>المخصّص (USD)</th></tr>
            </thead>
            <tbody>
                @foreach ($payment->activeAllocations as $alloc)
                    <tr>
                        <td class="num">{{ $alloc->invoice?->invoice_number ?? '—' }}</td>
                        <td class="num">${{ number_format((float) $alloc->allocated_usd, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @php $unallocated = (float) $payment->unallocatedUsd(); @endphp
    @if ($unallocated > 0)
        <p style="margin-top:12px; font-size:12px;" class="muted">رصيد دائن (غير مخصص): <span class="num">${{ number_format($unallocated, 2) }} USD</span> — يُحتسب لصالح العميل.</p>
    @endif

    <div style="margin-top:40px; display:flex; justify-content:space-between; font-size:12px;" class="muted">
        <div>استلمها: {{ $payment->receivedBy?->name ?? '—' }}</div>
        <div>التوقيع: ____________________</div>
    </div>

    <div style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:12px; font-size:12px;" class="muted">
        <p style="margin:0 0 6px; font-weight:600; color:#334155;">القيمة الرسمية لحساب العميل بالدولار الأمريكي (USD).</p>
        @if ($company['invoice_footer'])<p style="margin:0;">{{ $company['invoice_footer'] }}</p>@endif
    </div>
</x-print-layout>
