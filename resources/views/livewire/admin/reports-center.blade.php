<div>
    <x-page-header title="مركز التقارير" subtitle="كل التقارير في مكان واحد — تظهر حسب صلاحياتك" />

    <div class="space-y-6">
        @foreach ($groups as $group)
            @php $visible = collect($group['items'])->filter(fn ($i) => auth()->user()->can($i['permission'])); @endphp
            @if ($visible->isNotEmpty())
                <div>
                    <h3 class="mb-3 text-sm font-semibold text-slate-500">{{ $group['label'] }}</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($visible as $item)
                            <a href="{{ route($item['route']) }}" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 transition hover:border-brand-300 hover:bg-brand-50">
                                <span>{{ $item['label'] }}</span>
                                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
