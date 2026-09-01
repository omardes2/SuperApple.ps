{{-- Invoice totals card. Expects $totals (subtotal_usd/discount_usd/tax_usd/total_usd)
     and optionally $invoice (for the stored rate) and $rate (a live typed rate while
     editing a draft). Display standard: ILS primary, USD official value secondary.
     ILS is derived from the record's OWN rate (live typed on a draft, else the stored
     invoice rate) — never a current/global rate. Accounting is untouched. --}}
@php
    $liveRate = ($rate ?? null);
    $hasLive = $liveRate !== null && $liveRate !== '' && (float) $liveRate > 0;
    $docRate = $hasLive ? $liveRate : (isset($invoice) ? $invoice->exchange_rate : null);
    $hasRate = $docRate !== null && $docRate !== '' && (float) $docRate > 0;

    $conv = fn ($usd) => $hasRate ? \App\Support\Money::convertUsdToIls($usd, $docRate) : null;
    $subtotalIls = $conv($totals['subtotal_usd']);
    $discountIls = $conv($totals['discount_usd']);
    $taxIls = $conv($totals['tax_usd']);
    // Stored accounting ILS for an issued invoice; else derive from the rate.
    $totalIls = (! $hasLive && isset($invoice) && $invoice->total_ils_at_issue)
        ? $invoice->total_ils_at_issue : $conv($totals['total_usd']);
@endphp
<div class="rounded-xl border border-slate-200 bg-white p-5">
    <h3 class="mb-3 font-semibold text-slate-800">ملخص الفاتورة</h3>
    <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">المجموع الفرعي</dt><dd class="text-left"><x-amount :ils="$subtotalIls" :usd="$totals['subtotal_usd']" :usd-approx="false" class="text-slate-700" /></dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">الخصم</dt>
            <dd class="text-left text-slate-700" dir="ltr">
                @if ($discountIls !== null)−{{ \App\Support\Format::ils($discountIls) }} <span class="text-xs text-slate-400">−{{ \App\Support\Format::usd($totals['discount_usd']) }}</span>@else−{{ \App\Support\Format::usd($totals['discount_usd']) }}@endif
            </dd>
        </div>
        <div class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd class="text-left"><x-amount :ils="$taxIls" :usd="$totals['tax_usd']" :usd-approx="false" class="text-slate-700" /></dd></div>
        <div class="flex items-baseline justify-between border-t border-slate-200 pt-3">
            <dt class="text-sm font-semibold text-slate-800">الإجمالي</dt>
            <dd class="text-left"><x-amount :ils="$totalIls" :usd="$totals['total_usd']" :usd-approx="false" class="text-xl font-bold text-slate-900" /></dd>
        </div>
    </dl>
    <p class="mt-2 text-[11px] text-slate-400">القيمة الرسمية للفاتورة بالدولار الأمريكي (USD).</p>

    @isset($invoice)
        @if ($invoice->exchange_rate)
            <div class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                <p dir="ltr">سعر الصرف عند الإصدار: 1 USD = {{ $invoice->exchange_rate }} ILS</p>
            </div>
        @endif
        @if ($invoice->isIssued())
            <div class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">المدفوع</span><x-amount :ils="$conv($invoice->paid_usd_equivalent)" :usd="$invoice->paid_usd_equivalent" :usd-approx="false" /></div>
                <div class="flex justify-between font-semibold"><span class="text-slate-600">المتبقي</span><x-amount :ils="$conv($invoice->remaining_usd)" :usd="$invoice->remaining_usd" :usd-approx="false" /></div>
            </div>
        @endif
    @endisset
</div>
