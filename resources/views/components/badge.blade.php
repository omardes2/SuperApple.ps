@props(['class' => 'bg-slate-100 text-slate-600'])

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$class}"]) }}>
    {{ $slot }}
</span>
