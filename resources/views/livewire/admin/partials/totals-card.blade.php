{{-- Totals card. Expects $totals (subtotal_usd/discount_usd/tax_usd/total_usd) and optionally $invoice for ILS. --}}
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">المجموع الفرعي</dt><dd class="text-slate-700" dir="ltr">${{ number_format((float) $totals['subtotal_usd'], 2) }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd class="text-slate-700" dir="ltr">-${{ number_format((float) $totals['discount_usd'], 2) }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="text-slate-700" dir="ltr">${{ number_format((float) $totals['tax_usd'], 2) }}</dd></div>
        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold">
            <dt class="text-slate-800">الإجمالي</dt>
            <dd class="text-slate-900" dir="ltr">${{ number_format((float) $totals['total_usd'], 2) }} USD</dd>
        </div>
    </dl>

    @isset($invoice)
        @if ($invoice->exchange_rate)
            <div class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                <p dir="ltr">سعر الصرف عند الإصدار: 1 USD = {{ $invoice->exchange_rate }} ILS</p>
                @if ($invoice->total_ils_at_issue)
                    <p class="mt-1 font-semibold" dir="ltr">القيمة المكافئة: {{ number_format((float) $invoice->total_ils_at_issue, 2) }} ILS</p>
                @endif
                <p class="mt-1 text-slate-400">القيمة الأساسية المستحقة هي بالدولار الأمريكي.</p>
            </div>
        @endif
        @if ($invoice->isIssued())
            <div class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">المدفوع (USD)</span><span dir="ltr">${{ number_format((float) $invoice->paid_usd_equivalent, 2) }}</span></div>
                <div class="flex justify-between font-semibold"><span class="text-slate-600">المتبقي (USD)</span><span dir="ltr">${{ number_format((float) $invoice->remaining_usd, 2) }}</span></div>
            </div>
        @endif
    @endisset
</div>
