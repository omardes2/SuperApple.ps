<div x-data="{ open: false }" @click.outside="open = false" class="relative w-full max-w-md">
    <div class="relative">
        <input type="search" wire:model.live.debounce.300ms="q" @focus="open = true"
               placeholder="بحث سريع… (عميل، فاتورة INV-، مهمة، موظف…)"
               class="w-full rounded-lg border border-slate-300 bg-slate-50 py-2 pr-9 pl-3 text-sm focus:border-brand-400 focus:bg-white">
        <svg class="pointer-events-none absolute right-2.5 top-2.5 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <div wire:loading class="absolute left-2.5 top-3 h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-brand-500"></div>
    </div>

    @if (strlen(trim($q)) >= 2)
        <div x-show="open" x-cloak class="absolute left-0 right-0 z-30 mt-1 max-h-96 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
            @forelse ($groups as $group)
                <div class="px-3 py-1.5 text-xs font-semibold text-slate-400">{{ $group['label'] }}</div>
                @foreach ($group['items'] as $item)
                    <a href="{{ route($item['route'], $item['id']) }}" class="flex items-center justify-between px-3 py-2 text-sm hover:bg-brand-50">
                        <span class="text-slate-700">{{ $item['title'] }}</span>
                        @if ($item['subtitle'])<span class="text-xs text-slate-400" dir="ltr">{{ $item['subtitle'] }}</span>@endif
                    </a>
                @endforeach
            @empty
                <div class="px-3 py-6 text-center text-sm text-slate-400">لا نتائج مطابقة.</div>
            @endforelse
        </div>
    @endif
</div>
