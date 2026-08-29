<div>
    <x-page-header title="سجل النشاط" subtitle="أهم أحداث النظام — تظهر حسب صلاحياتك" />

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <ol class="divide-y divide-slate-100">
            @forelse ($rows as $l)
                <li class="flex items-start gap-3 px-4 py-3">
                    <span class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><x-icon name="shield" class="h-4 w-4" /></span>
                    <div class="flex-1">
                        <div class="text-sm text-slate-800">{{ $l->description ?: $l->action }}</div>
                        <div class="mt-0.5 text-xs text-slate-400"><span>{{ $l->user?->name ?? 'النظام' }}</span> · <span>{{ $l->module }}</span> · <span dir="ltr">{{ $l->created_at?->diffForHumans() }}</span></div>
                    </div>
                    <x-badge>{{ $l->action }}</x-badge>
                </li>
            @empty
                <li class="px-4 py-12 text-center text-slate-400">لا نشاط.</li>
            @endforelse
        </ol>
    </div>
</div>
