<div>
    <x-page-header title="القيود المحاسبية" subtitle="دفتر اليومية — جميع القيم بالشيكل (ILS)" />

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث برقم القيد/البيان..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">رقم القيد</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">البيان</th><th class="px-4 py-3">النوع</th><th class="px-4 py-3">الحالة</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($journals as $j)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.journals.show', $j) }}" class="hover:text-brand-600 hover:underline">{{ $j->journal_number }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $j->entry_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $j->description }}</td>
                        <td class="px-4 py-3 text-slate-400 text-xs" dir="ltr">{{ $j->posting_type }}</td>
                        <td class="px-4 py-3"><x-badge :class="$j->status->badgeClass()">{{ $j->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا قيود.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $journals->links() }}</div>
</div>
