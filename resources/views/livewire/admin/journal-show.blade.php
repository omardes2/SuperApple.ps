<div>
    <x-page-header :title="'قيد '.$journal->journal_number" :subtitle="$journal->description">
        <x-slot:actions>
            <a href="{{ route('admin.journals') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
            @can('reverse', $journal)
                @if ($journal->isPosted() && ! $journal->is_reversal)
                    <button wire:click="openReverse" class="rounded-lg border border-amber-300 px-4 py-2 text-sm text-amber-700 hover:bg-amber-50">عكس القيد</button>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-badge :class="$journal->status->badgeClass()">{{ $journal->status->label() }}</x-badge>
        <span class="text-sm text-slate-500" dir="ltr">{{ $journal->entry_date->format('Y-m-d') }}</span>
        @if ($journal->reversalEntry)<span class="text-xs text-amber-600">عُكس بالقيد {{ $journal->reversalEntry->journal_number }}</span>@endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الحساب</th><th class="px-4 py-3">البيان</th><th class="px-4 py-3">مدين</th><th class="px-4 py-3">دائن</th><th class="px-4 py-3">أصل العملية</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($journal->lines as $line)
                    <tr>
                        <td class="px-4 py-2 text-slate-800"><span class="font-mono text-slate-400" dir="ltr">{{ $line->account->code }}</span> {{ $line->account->name }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $line->description }}</td>
                        <td class="px-4 py-2" dir="ltr">{{ (float) $line->debit_ils ? number_format((float) $line->debit_ils, 2) : '' }}</td>
                        <td class="px-4 py-2" dir="ltr">{{ (float) $line->credit_ils ? number_format((float) $line->credit_ils, 2) : '' }}</td>
                        <td class="px-4 py-2 text-xs text-slate-400" dir="ltr">{{ $line->original_currency ? number_format((float) $line->original_amount, 2).' '.$line->original_currency.' @ '.$line->exchange_rate : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-slate-50 font-bold">
                <tr><td colspan="2" class="px-4 py-3 text-left">الإجمالي</td><td class="px-4 py-3" dir="ltr">{{ number_format((float) $journal->totalDebit(), 2) }}</td><td class="px-4 py-3" dir="ltr">{{ number_format((float) $journal->totalCredit(), 2) }}</td><td></td></tr>
            </tfoot>
        </table>
    </div>

    <x-modal show="showReverse" title="عكس القيد">
        <p class="mb-3 text-sm text-slate-600">سيتم إنشاء قيد عكسي معاكس. القيد الأصلي يبقى محفوظاً.</p>
        <label class="mb-1 block text-sm text-slate-600">السبب (اختياري)</label>
        <textarea wire:model="reverseReason" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        @error('reverseReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="$wire.showReverse = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button>
            <button wire:click="confirmReverse" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">تأكيد العكس</button>
        </div>
    </x-modal>
</div>
