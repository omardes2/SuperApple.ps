<div>
    <x-page-header title="المصاريف" subtitle="القيمة المحاسبية بالشيكل (ILS)">
        <x-slot:actions>
            @can('create', \App\Models\Expense::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مصروف</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3">
        <x-stat-card label="مصاريف الشهر" :value="number_format((float) $stats['month_ils'], 2).' ₪'" hint="مُرحّلة" icon="minus" tone="red" />
        <x-stat-card label="مُرحّلة" :value="$stats['posted']" icon="book" tone="emerald" />
        <x-stat-card label="مسودات" :value="$stats['draft']" icon="doc" tone="slate" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الفئات</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الوصف</th><th class="px-4 py-3">الفئة</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">ILS</th><th class="px-4 py-3">الحالة</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($expenses as $e)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.expenses.show', $e) }}" class="hover:text-brand-600 hover:underline">{{ $e->expense_number }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $e->expense_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ \Illuminate\Support\Str::limit($e->description, 40) }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $e->category?->name }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $e->amount, 2) }} {{ $e->currency }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800" dir="ltr">{{ number_format((float) $e->amount_ils, 2) }} ₪</td>
                        <td class="px-4 py-3"><x-badge :class="$e->status->badgeClass()">{{ $e->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا مصاريف.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $expenses->links() }}</div>
</div>
