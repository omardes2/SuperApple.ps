{{--
    Customer account statement table (USD official). Expects:
      $statement — from CustomerStatementService::build() (entries[], closing_balance_usd)
    Read-only presentation; all figures come from the statement service.
--}}
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
            <tr>
                <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المرجع</th>
                <th class="px-4 py-3">البيان</th><th class="px-4 py-3">مدين (فاتورة)</th>
                <th class="px-4 py-3">دائن (دفعة)</th><th class="px-4 py-3">الرصيد</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($statement['entries'] as $row)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $row['date']->format('Y-m-d') }}</td>
                    <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $row['reference'] }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ $row['description'] }}</td>
                    <td class="px-4 py-3 text-slate-800" dir="ltr">{{ (float) $row['debit_usd'] > 0 ? '$'.number_format((float) $row['debit_usd'], 2) : '—' }}</td>
                    <td class="px-4 py-3 text-emerald-700" dir="ltr">{{ (float) $row['credit_usd'] > 0 ? '$'.number_format((float) $row['credit_usd'], 2) : '—' }}</td>
                    <td class="px-4 py-3"><x-money :usd="$row['balance_usd']" :useLatest="true" class="font-semibold text-slate-800" dir="ltr" /></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">لا حركات على الحساب.</td></tr>
            @endforelse
        </tbody>
        <tfoot class="bg-slate-50 text-sm font-semibold">
            <tr>
                <td colspan="5" class="px-4 py-3 text-left text-slate-600">الرصيد الختامي (USD)</td>
                <td class="px-4 py-3"><x-money :usd="$statement['closing_balance_usd']" :useLatest="true" class="text-slate-900" dir="ltr" /></td>
            </tr>
        </tfoot>
    </table>
</div>
