<div>
    <x-page-header title="عروض الأسعار" subtitle="الأسعار بالدولار الأمريكي (USD)">
        <x-slot:actions>
            @can('create', \App\Models\Quotation::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ عرض سعر</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="مسودات" :value="$stats['draft']" icon="doc" tone="slate" />
        <x-stat-card label="مُرسلة" :value="$stats['sent']" icon="chat" tone="brand" />
        <x-stat-card label="مقبولة" :value="$stats['accepted']" icon="check" tone="emerald" />
        <x-stat-card label="منتهية" :value="$stats['expired']" icon="clock" tone="amber" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالرقم/العميل..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل العملاء</option>
            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">العميل</th>
                    <th class="px-4 py-3">المشروع</th><th class="px-4 py-3">التاريخ</th>
                    <th class="px-4 py-3">الصلاحية</th><th class="px-4 py-3">الإجمالي</th><th class="px-4 py-3">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($quotations as $q)
                    @php $eff = $q->effectiveStatus(); @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $q->quotation_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route('admin.quotations.show', $q) }}" class="hover:text-brand-600 hover:underline">{{ $q->customer->name }}</a></td>
                        <td class="px-4 py-3 text-slate-600">{{ $q->project?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $q->quotation_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $q->valid_until?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">${{ number_format((float) $q->total_usd, 2) }}</td>
                        <td class="px-4 py-3"><x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا عروض أسعار.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $quotations->links() }}</div>
</div>
