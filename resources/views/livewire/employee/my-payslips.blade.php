<div>
    <x-page-header title="قسائم راتبي" subtitle="قسائم الرواتب الخاصة بك" />

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الشهر</th><th class="px-4 py-3">الأساسي</th><th class="px-4 py-3">الاستحقاقات</th><th class="px-4 py-3">الاستقطاعات</th><th class="px-4 py-3">الصافي</th><th class="px-4 py-3">مدفوع</th><th class="px-4 py-3">المتبقي</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($items as $item)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800" dir="ltr">{{ $item->payrollRun->periodLabel() }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format((float) $item->base_salary_ils, 2) }}</td>
                        <td class="px-4 py-3 text-emerald-700" dir="ltr">{{ number_format((float) ($item->overtime_amount_ils + $item->allowances_ils + $item->bonuses_ils + $item->commissions_ils), 2) }}</td>
                        <td class="px-4 py-3 text-red-600" dir="ltr">{{ number_format((float) $item->total_deductions_ils, 2) }}</td>
                        <td class="px-4 py-3 font-bold text-slate-900" dir="ltr">{{ number_format((float) $item->net_salary_ils, 2) }}</td>
                        <td class="px-4 py-3 text-emerald-700" dir="ltr">{{ number_format((float) $item->paid_amount_ils, 2) }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ number_format((float) $item->remaining_payable_ils, 2) }}</td>
                        <td class="px-4 py-3"><a href="{{ route('payslips.print', $item) }}" target="_blank" class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-50">طباعة</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا قسائم رواتب بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
