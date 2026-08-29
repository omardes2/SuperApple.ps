<div>
    <x-page-header title="تقارير الرواتب" subtitle="بالشيكل (ILS)">
        <x-slot:actions><button onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</button></x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['summary' => 'ملخص المسير', 'outstanding' => 'الرواتب المستحقة', 'advances' => 'السلف', 'payments' => 'المدفوعات'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-brand-600 text-white' : 'border border-slate-300 text-slate-600' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if (in_array($tab, ['summary']))
        <div class="mb-4"><select wire:model.live="run" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">أحدث مسير</option>@foreach ($runs as $r)<option value="{{ $r->id }}">{{ $r->periodLabel() }} — {{ $r->payroll_number }}</option>@endforeach</select></div>
        @if ($selected)
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-700">حسب القسم — {{ $selected->periodLabel() }}</div>
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">القسم</th><th class="px-4 py-3">الموظفون</th><th class="px-4 py-3">الإجمالي</th><th class="px-4 py-3">الاستقطاعات</th><th class="px-4 py-3">الصافي</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($byDepartment as $row)
                            <tr><td class="px-4 py-2 text-slate-700">{{ $row['department'] }}</td><td class="px-4 py-2" dir="ltr">{{ $row['count'] }}</td><td class="px-4 py-2" dir="ltr">{{ number_format((float) $row['gross'], 2) }}</td><td class="px-4 py-2" dir="ltr">{{ number_format((float) $row['deductions'], 2) }}</td><td class="px-4 py-2 font-medium" dir="ltr">{{ number_format((float) $row['net'], 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else<p class="text-slate-400">لا مسيرات.</p>@endif
    @endif

    @if ($tab === 'outstanding')
        <div class="mb-3 text-sm text-slate-600">إجمالي المستحق: <span class="font-bold" dir="ltr">{{ number_format((float) $outstandingTotal, 2) }} ₪</span></div>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الموظف</th><th class="px-4 py-3">المسير</th><th class="px-4 py-3">الصافي</th><th class="px-4 py-3">مدفوع</th><th class="px-4 py-3">المتبقي</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($outstanding as $i)
                        <tr><td class="px-4 py-2 text-slate-700">{{ $i->employee_name_snapshot }}</td><td class="px-4 py-2 text-slate-500" dir="ltr">{{ $i->payrollRun->periodLabel() }}</td><td class="px-4 py-2" dir="ltr">{{ number_format((float) $i->net_salary_ils, 2) }}</td><td class="px-4 py-2 text-emerald-700" dir="ltr">{{ number_format((float) $i->paid_amount_ils, 2) }}</td><td class="px-4 py-2 font-semibold text-amber-600" dir="ltr">{{ number_format((float) $i->remaining_payable_ils, 2) }}</td></tr>
                    @empty<tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا مستحقات.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'advances')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الموظف</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">المتبقي</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($advances as $a)
                        <tr><td class="px-4 py-2 font-mono text-slate-500" dir="ltr">{{ $a->advance_number }}</td><td class="px-4 py-2 text-slate-700">{{ $a->employee?->full_name }}</td><td class="px-4 py-2" dir="ltr">{{ number_format((float) $a->amount_ils, 2) }}</td><td class="px-4 py-2" dir="ltr">{{ number_format((float) $a->remaining_ils, 2) }}</td><td class="px-4 py-2"><x-badge :class="$a->status->badgeClass()">{{ $a->status->label() }}</x-badge></td></tr>
                    @empty<tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا سلف.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'payments')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الموظف</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">الحساب</th><th class="px-4 py-3">التاريخ</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $p)
                        <tr><td class="px-4 py-2 font-mono text-slate-500" dir="ltr">{{ $p->payment_number }}</td><td class="px-4 py-2 text-slate-700">{{ $p->item?->employee_name_snapshot }}</td><td class="px-4 py-2" dir="ltr">{{ number_format((float) $p->amount_ils, 2) }}</td><td class="px-4 py-2 text-slate-500">{{ $p->financialAccount?->name }}</td><td class="px-4 py-2 text-slate-500" dir="ltr">{{ $p->payment_date->format('Y-m-d') }}</td></tr>
                    @empty<tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا مدفوعات.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
