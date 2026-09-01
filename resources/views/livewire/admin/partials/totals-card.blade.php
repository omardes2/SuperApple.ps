{{-- Totals card. Expects $totals (subtotal_usd/discount_usd/tax_usd/total_usd) and optionally $invoice for the locked rate. --}}
@php
    // ILS context (display only): while editing a draft, an optional live $rate
    // (the rate being typed into the form) is used so the ILS equivalent updates
    // as the invoice is filled. Otherwise an invoice uses its own locked rate,
    // and a draft/quotation with no rate shows no ILS (the central-rate estimate
    // was retired).
    $liveRate = ($rate ?? null);
    $hasLive = $liveRate !== null && $liveRate !== '' && (float) $liveRate > 0;
    $docRate = $hasLive ? $liveRate : (isset($invoice) ? $invoice->exchange_rate : null);
    $docUseLatest = ($docRate === null || $docRate === '');
    // Use the stored accounting ILS only when NOT overriding with a live rate
    // (an issued document); a live draft computes ILS from the typed rate.
    $totalIls = (! $hasLive && isset($invoice) && $invoice->total_ils_at_issue) ? $invoice->total_ils_at_issue : null;
@endphp
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="mb-3 font-semibold text-slate-800">ملخص الفاتورة</h3>
    <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">المجموع الفرعي</dt><dd class="text-left"><x-money :usd="$totals['subtotal_usd']" :rate="$docRate" :useLatest="$docUseLatest" class="text-slate-700" dir="ltr" /></dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd class="text-left text-slate-700" dir="ltr">-${{ number_format((float) $totals['discount_usd'], 2) }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="text-left"><x-money :usd="$totals['tax_usd']" :rate="$docRate" :useLatest="$docUseLatest" class="text-slate-700" dir="ltr" /></dd></div>
        <div class="flex items-baseline justify-between border-t border-slate-200 pt-3">
            <dt class="text-sm font-semibold text-slate-800">الإجمالي</dt>
            <dd class="text-left"><x-money :usd="$totals['total_usd']" :ils="$totalIls" :rate="$docRate" :useLatest="$docUseLatest" class="text-xl font-bold text-slate-900" dir="ltr" /></dd>
        </div>
    </dl>

    @isset($invoice)
        @if ($invoice->exchange_rate)
            <div class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                <p dir="ltr">سعر الصرف عند الإصدار: 1 USD = {{ $invoice->exchange_rate }} ILS</p>
                <p class="mt-1 text-slate-400">القيمة الأساسية المستحقة هي بالدولار الأمريكي.</p>
            </div>
        @endif
        @if ($invoice->isIssued())
            <div class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">المدفوع (USD)</span><x-money :usd="$invoice->paid_usd_equivalent" :rate="$invoice->exchange_rate" dir="ltr" /></div>
                <div class="flex justify-between font-semibold"><span class="text-slate-600">المتبقي (USD)</span><x-money :usd="$invoice->remaining_usd" :rate="$invoice->exchange_rate" dir="ltr" /></div>
            </div>
        @endif
    @endisset
</div>
