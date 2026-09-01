@props([
    'ils' => null,        // primary amount in ILS (the base display currency)
    'usd' => null,        // optional secondary USD reference (invoice/official value or equivalent)
    'usdApprox' => true,  // secondary shows "≈ $x" (an equivalent) vs "$x" (the official document value)
    'secondary' => true,  // render the secondary USD line at all
])
{{--
    Standard money display: ILS is the PRIMARY (base) figure; USD is a smaller
    muted reference underneath. Never converts with a current/global rate — the
    caller passes an ILS value already derived from the record's own stored rate
    or accounting value. If no ILS is available (e.g. a draft with no rate), the
    USD value is shown alone so nothing is invented.
--}}
@php
    $hasIls = $ils !== null && $ils !== '';
    $hasUsd = $usd !== null && $usd !== '';
@endphp
@if ($hasIls)
    <span class="inline-flex flex-col leading-tight">
        <span {{ $attributes->merge(['dir' => 'ltr']) }}>{{ \App\Support\Format::ils($ils) }}</span>
        @if ($secondary && $hasUsd)
            <span class="mt-0.5 text-xs font-normal text-slate-400" dir="ltr">{{ $usdApprox ? '≈ ' : '' }}{{ \App\Support\Format::usd($usd) }}</span>
        @endif
    </span>
@else
    <span {{ $attributes->merge(['dir' => 'ltr']) }}>{{ $hasUsd ? \App\Support\Format::usd($usd) : '—' }}</span>
@endif
