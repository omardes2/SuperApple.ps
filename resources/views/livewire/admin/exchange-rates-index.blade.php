<div>
    <x-page-header title="أسعار الصرف" subtitle="USD → ILS · سعر واحد معتمد لكل يوم">
        <x-slot:actions>
            @can('exchange_rates.manage')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ سعر صرف</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-700">
        سعر الصرف يُثبّت داخل كل فاتورة عند إصدارها. تعديل الجدول هنا لا يغيّر أي فاتورة صادرة سابقاً.
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الزوج</th><th class="px-4 py-3">السعر</th><th class="px-4 py-3">المصدر</th><th class="px-4 py-3">ملاحظات</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rates as $rate)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rate->rate_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $rate->base_currency }}/{{ $rate->quote_currency }}</td>
                        <td class="px-4 py-3 font-mono font-semibold text-slate-800" dir="ltr">1 USD = {{ $rate->rate }} ILS</td>
                        <td class="px-4 py-3 text-slate-600">{{ $rate->source->label() }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $rate->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا أسعار صرف بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rates->links() }}</div>

    <x-modal show="showForm" title="سعر صرف جديد">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">التاريخ</label>
                    <input type="date" wire:model="rate_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('rate_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">1 USD = ? ILS</label>
                    <input type="number" step="0.000001" wire:model="rate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">المصدر</label>
                    <select wire:model="source" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($sourceOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات</label>
                    <input type="text" wire:model="notes" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
