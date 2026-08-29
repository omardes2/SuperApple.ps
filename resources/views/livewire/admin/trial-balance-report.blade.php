<div>
    <x-page-header title="ميزان المراجعة" subtitle="جميع القيم بالشيكل (ILS)">
        <x-slot:actions><button onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</button></x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div><label class="mb-1 block text-xs text-slate-500">من</label><input type="date" wire:model.live="from" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        <div><label class="mb-1 block text-xs text-slate-500">إلى</label><input type="date" wire:model.live="to" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الحساب</th><th class="px-4 py-3">مدين الفترة</th><th class="px-4 py-3">دائن الفترة</th><th class="px-4 py-3">رصيد مدين</th><th class="px-4 py-3">رصيد دائن</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($report['rows'] as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2 text-slate-800"><span class="font-mono text-slate-400" dir="ltr">{{ $row['account']->code }}</span> {{ $row['account']->name }}</td>
                        <td class="px-4 py-2 text-slate-600" dir="ltr">{{ number_format((float) $row['period_debit'], 2) }}</td>
                        <td class="px-4 py-2 text-slate-600" dir="ltr">{{ number_format((float) $row['period_credit'], 2) }}</td>
                        <td class="px-4 py-2 font-medium text-slate-800" dir="ltr">{{ (float) $row['ending_debit'] ? number_format((float) $row['ending_debit'], 2) : '—' }}</td>
                        <td class="px-4 py-2 font-medium text-slate-800" dir="ltr">{{ (float) $row['ending_credit'] ? number_format((float) $row['ending_credit'], 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا حركات.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-slate-50 font-bold">
                <tr>
                    <td class="px-4 py-3 text-left">الإجمالي</td>
                    <td class="px-4 py-3" dir="ltr">{{ number_format((float) $report['totals']['period_debit'], 2) }}</td>
                    <td class="px-4 py-3" dir="ltr">{{ number_format((float) $report['totals']['period_credit'], 2) }}</td>
                    <td class="px-4 py-3" dir="ltr">{{ number_format((float) $report['totals']['ending_debit'], 2) }}</td>
                    <td class="px-4 py-3" dir="ltr">{{ number_format((float) $report['totals']['ending_credit'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @php $balanced = (float) $report['totals']['ending_debit'] === (float) $report['totals']['ending_credit']; @endphp
    <p class="mt-3 text-sm {{ $balanced ? 'text-emerald-700' : 'text-red-600' }}">{{ $balanced ? '✓ الميزان متوازن (مدين = دائن).' : '✗ الميزان غير متوازن!' }}</p>
</div>
