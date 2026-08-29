<div>
    <x-page-header title="الموردون" subtitle="أرصدة الذمم الدائنة بالشيكل (ILS)">
        <x-slot:actions>
            @can('create', \App\Models\Supplier::class)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مورد</button>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم/الرقم..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">المورد</th><th class="px-4 py-3">الهاتف</th><th class="px-4 py-3">مستحق عليه (ILS)</th><th class="px-4 py-3">الحالة</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($suppliers as $s)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $s->supplier_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route('admin.suppliers.show', $s) }}" class="hover:text-brand-600 hover:underline">{{ $s->name }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $s->phone ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">{{ number_format((float) ($outstanding[$s->id] ?? 0), 2) }} ₪</td>
                        <td class="px-4 py-3"><x-badge :class="$s->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ $s->is_active ? 'نشط' : 'غير نشط' }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا موردين.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $suppliers->links() }}</div>

    <x-modal show="showCreate" title="مورد جديد">
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">الاسم</label><input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm text-slate-600">الهاتف</label><input type="text" wire:model="phone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="mb-1 block text-sm text-slate-600">النوع</label><input type="text" wire:model="supplier_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="$wire.showCreate = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button>
            <button wire:click="save" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
        </div>
    </x-modal>
</div>
