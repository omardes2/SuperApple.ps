@props(['title' => '', 'subtitle' => null, 'breadcrumbs' => []])

<div class="mb-5">
    @if (! empty($breadcrumbs))
        <nav class="mb-2 flex flex-wrap items-center gap-1 text-xs text-slate-400">
            @foreach ($breadcrumbs as $crumb)
                @if (! $loop->first)<span>›</span>@endif
                @if (is_array($crumb) && ! empty($crumb['route']))
                    <a href="{{ route($crumb['route'], $crumb['params'] ?? []) }}" class="hover:text-brand-600">{{ $crumb['label'] }}</a>
                @else
                    <span class="text-slate-600">{{ is_array($crumb) ? $crumb['label'] : $crumb }}</span>
                @endif
            @endforeach
        </nav>
    @endif
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-slate-800">{{ $title }}</h2>
            @if ($subtitle)
                <p class="text-sm text-slate-500">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
