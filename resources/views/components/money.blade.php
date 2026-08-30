@props([
    'usd' => 0,          // the primary USD amount (official value)
    'rate' => null,      // exchange rate for THIS amount's context (invoice/payment rate)
    'ils' => null,       // a precomputed ILS equivalent (aggregates / stored accounting value)
    'secondary' => true, // render the "≈ X ₪" line
    'useLatest' => false, // legacy fallback flag; the central-rate estimate was retired, so this now no-ops
])
@php
    // Resolve the ILS equivalent without ever touching accounting:
    //   1) an explicit precomputed value (aggregates, total_ils_at_issue), else
    //   2) this amount's own document rate (invoice/payment/historical), else
    //   3) nothing — the standalone exchange-rate module was retired, so there is
    //      no central/latest/default rate and `useLatest` yields null.
    // No valid rate ⇒ no second line (never a 500, never an invented rate).
    $ilsValue = $ils;
    if ($ilsValue === null && $rate !== null && $rate !== '' && (float) $rate > 0) {
        $ilsValue = \App\Support\Money::convertUsdToIls($usd ?? 0, $rate);
    }
    if ($ilsValue === null && $useLatest) {
        $ilsValue = app(\App\Support\CurrencyDisplay::class)->estimatedIls($usd ?? 0);
    }
@endphp
<span class="inline-flex flex-col leading-tight">
    <span {{ $attributes }}>{{ \App\Support\Format::usd($usd) }}</span>
    @if ($secondary && $ilsValue !== null)
        <span class="mt-0.5 text-xs font-normal text-slate-400" dir="ltr">≈ {{ \App\Support\Format::ils($ilsValue) }}</span>
    @endif
</span>
