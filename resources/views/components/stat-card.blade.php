@props([
    'label' => '',
    'value' => '—',
    'hint' => null,
    'icon' => 'dot',
    'tone' => 'brand',
])

@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600',
        'emerald' => 'bg-emerald-50 text-emerald-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'red' => 'bg-red-50 text-red-600',
        'violet' => 'bg-violet-50 text-violet-600',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
    $toneClass = $tones[$tone] ?? $tones['brand'];
@endphp

<div class="rounded-xl border border-slate-200 bg-white p-4">
    <div class="flex items-start justify-between">
        <div>
            <p class="text-sm text-slate-500">{{ $label }}</p>
            <p class="mt-1 text-2xl font-bold text-slate-800">{{ $value }}</p>
            @if ($hint)
                <p class="mt-0.5 text-xs text-slate-400">{{ $hint }}</p>
            @endif
        </div>
        <span class="flex h-10 w-10 items-center justify-center rounded-lg {{ $toneClass }}">
            <x-icon :name="$icon" />
        </span>
    </div>
</div>
