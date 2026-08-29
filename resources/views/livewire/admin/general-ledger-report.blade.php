<div>
    <x-page-header title="دفتر الأستاذ العام" subtitle="حركة حساب مع الرصيد الجاري (ILS)" />

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex-1 min-w-64">
            <label class="mb-1 block text-xs text-slate-500">الحساب</label>
            <select wire:model.live="account" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">— اختر حساباً —</option>
                @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} — {{ $a->name }}</option>@endforeach
            </select>
        </div>
        <div><label class="mb-1 block text-xs text-slate-500">من</label><input type="date" wire:model.live="from" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        <div><label class="mb-1 block text-xs text-slate-500">إلى</label><input type="date" wire:model.live="to" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
    </div>

    @if ($ledger)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">القيد</th><th class="px-4 py-3">البيان</th><th class="px-4 py-3">مدين</th><th class="px-4 py-3">دائن</th><th class="px-4 py-3">الرصيد</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr class="bg-slate-50"><td colspan="5" class="px-4 py-2 text-left font-medium text-slate-600">رصيد افتتاحي</td><td class="px-4 py-2 font-medium" dir="ltr">{{ number_format((float) $ledger['opening'], 2) }}</td></tr>
                    @foreach ($ledger['rows'] as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 text-slate-600" dir="ltr">{{ $row['entry']->entry_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-2 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.journals.show', $row['entry']) }}" class="hover:text-brand-600 hover:underline">{{ $row['entry']->journal_number }}</a></td>
                            <td class="px-4 py-2 text-slate-600">{{ $row['line']->description }}</td>
                            <td class="px-4 py-2" dir="ltr">{{ (float) $row['line']->debit_ils ? number_format((float) $row['line']->debit_ils, 2) : '' }}</td>
                            <td class="px-4 py-2" dir="ltr">{{ (float) $row['line']->credit_ils ? number_format((float) $row['line']->credit_ils, 2) : '' }}</td>
                            <td class="px-4 py-2 font-medium" dir="ltr">{{ number_format((float) $row['balance'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50 font-bold"><tr><td colspan="5" class="px-4 py-3 text-left">الرصيد الختامي</td><td class="px-4 py-3" dir="ltr">{{ number_format((float) $ledger['closing'], 2) }}</td></tr></tfoot>
            </table>
        </div>
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-slate-400">اختر حساباً لعرض حركته.</div>
    @endif
</div>
