<div>
    <x-page-header :title="'مسير رواتب '.$run->periodLabel()" :subtitle="$run->payroll_number">
        <x-slot:actions>
            <a href="{{ route('admin.payroll') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
            @if ($canReverse)<button wire:click="openReverse" class="rounded-lg border border-amber-300 px-4 py-2 text-sm text-amber-700 hover:bg-amber-50">عكس المسير</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <x-badge :class="$run->status->badgeClass()">{{ $run->status->label() }}</x-badge>
        <div class="mr-auto flex flex-wrap gap-2">
            @if ($canCalculate)<button wire:click="calculate" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">احتساب</button>@endif
            @if ($canApprove)<button wire:click="approve" wire:confirm="سيتم تجميد الاحتساب. متابعة؟" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">اعتماد</button>@endif
            @if ($canPost)<button wire:click="post" wire:confirm="سيتم ترحيل الرواتب محاسبياً. متابعة؟" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">ترحيل محاسبي</button>@endif
        </div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="الإجمالي" :value="number_format((float) $run->total_gross_ils, 2).' ₪'" icon="wallet" tone="brand" />
        <x-stat-card label="الاستقطاعات" :value="number_format((float) $run->total_deductions_ils, 2).' ₪'" icon="minus" tone="amber" />
        <x-stat-card label="السلف المستردة" :value="number_format((float) $run->total_advances_ils, 2).' ₪'" icon="repeat" tone="slate" />
        <x-stat-card label="صافي المستحق" :value="number_format((float) $run->total_net_ils, 2).' ₪'" icon="cash" tone="emerald" />
    </div>

    @if ($departments->isNotEmpty())
        <div class="mb-4"><select wire:model.live="department" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">كل الأقسام</option>@foreach ($departments as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select></div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-3 py-3">الموظف</th><th class="px-3 py-3">القسم</th><th class="px-3 py-3">الأساسي</th><th class="px-3 py-3">حضور</th><th class="px-3 py-3">غياب</th><th class="px-3 py-3">إضافي</th><th class="px-3 py-3">استحقاقات</th><th class="px-3 py-3">سلف</th><th class="px-3 py-3">الإجمالي</th><th class="px-3 py-3">الصافي</th><th class="px-3 py-3">مدفوع</th><th class="px-3 py-3">المتبقي</th><th class="px-3 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($items as $item)
                    <tr class="hover:bg-slate-50 cursor-pointer" wire:click="toggleItem({{ $item->id }})">
                        <td class="px-3 py-2 font-medium text-slate-800">{{ $item->employee_name_snapshot }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ $item->department_snapshot ?? '—' }}</td>
                        <td class="px-3 py-2" dir="ltr">{{ number_format((float) $item->base_salary_ils, 2) }}</td>
                        <td class="px-3 py-2 text-slate-500" dir="ltr">{{ (int) $item->attended_days }}/{{ (int) $item->working_days }}</td>
                        <td class="px-3 py-2 text-slate-500" dir="ltr">{{ (int) $item->absent_days }}</td>
                        <td class="px-3 py-2 text-slate-500" dir="ltr">{{ number_format((float) $item->overtime_amount_ils, 2) }}</td>
                        <td class="px-3 py-2 text-emerald-700" dir="ltr">{{ number_format((float) ($item->allowances_ils + $item->bonuses_ils + $item->commissions_ils), 2) }}</td>
                        <td class="px-3 py-2 text-amber-700" dir="ltr">{{ number_format((float) $item->advances_deduction_ils, 2) }}</td>
                        <td class="px-3 py-2 font-medium" dir="ltr">{{ number_format((float) $item->gross_salary_ils, 2) }}</td>
                        <td class="px-3 py-2 font-bold text-slate-900" dir="ltr">{{ number_format((float) $item->net_salary_ils, 2) }}</td>
                        <td class="px-3 py-2 text-emerald-700" dir="ltr">{{ number_format((float) $item->paid_amount_ils, 2) }}</td>
                        <td class="px-3 py-2 font-semibold {{ (float) $item->remaining_payable_ils > 0 ? 'text-amber-600' : 'text-emerald-700' }}" dir="ltr">{{ number_format((float) $item->remaining_payable_ils, 2) }}</td>
                        <td class="px-3 py-2">
                            @if ($canPay && (float) $item->remaining_payable_ils > 0)
                                <button wire:click.stop="openPay({{ $item->id }})" class="rounded-lg border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50">دفع</button>
                            @endif
                        </td>
                    </tr>
                    @if ($expandedItem === $item->id)
                        <tr class="bg-slate-50"><td colspan="13" class="px-4 py-3">
                            @php $s = $item->calculation_snapshot ?? []; @endphp
                            <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-xs text-slate-600 sm:grid-cols-4">
                                <div>الراتب الأساسي: <span dir="ltr">{{ number_format((float) $item->base_salary_ils, 2) }}</span></div>
                                <div>معدل اليوم: <span dir="ltr">{{ number_format((float) ($s['daily_rate'] ?? 0), 2) }}</span></div>
                                <div>إضافي ({{ (int) $item->overtime_minutes }}د): <span dir="ltr">+{{ number_format((float) $item->overtime_amount_ils, 2) }}</span></div>
                                <div>بدلات: <span dir="ltr">+{{ number_format((float) $item->allowances_ils, 2) }}</span></div>
                                <div>مكافآت: <span dir="ltr">+{{ number_format((float) $item->bonuses_ils, 2) }}</span></div>
                                <div>عمولات: <span dir="ltr">+{{ number_format((float) $item->commissions_ils, 2) }}</span></div>
                                <div>خصم غياب ({{ (int) $item->absent_days }} يوم): <span dir="ltr">-{{ number_format((float) $item->absence_deduction_ils, 2) }}</span></div>
                                <div>خصم تأخير: <span dir="ltr">-{{ number_format((float) $item->late_deduction_ils, 2) }}</span></div>
                                <div>إجازة غير مدفوعة: <span dir="ltr">-{{ number_format((float) $item->unpaid_leave_deduction_ils, 2) }}</span></div>
                                <div>استقطاعات أخرى: <span dir="ltr">-{{ number_format((float) $item->other_deductions_ils, 2) }}</span></div>
                                <div>استرداد سلف: <span dir="ltr">-{{ number_format((float) $item->advances_deduction_ils, 2) }}</span></div>
                                <div class="font-bold text-slate-800">الصافي: <span dir="ltr">{{ number_format((float) $item->net_salary_ils, 2) }}</span></div>
                            </div>
                        </td></tr>
                    @endif
                @empty
                    <tr><td colspan="13" class="px-4 py-10 text-center text-slate-400">لا بنود — قم بالاحتساب.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal show="showPay" title="دفع راتب">
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">المبلغ</label><input type="number" step="0.01" wire:model="payAmount" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('payAmount')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm text-slate-600">الحساب النقدي/البنكي</label><select wire:model="payAccountId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showPay=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="confirmPay" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">دفع</button></div>
    </x-modal>

    <x-modal show="showReverse" title="عكس مسير الرواتب">
        <p class="mb-3 text-sm text-slate-600">سيتم عكس القيد المحاسبي واسترجاع السلف. يجب عكس مدفوعات الرواتب أولاً.</p>
        <textarea wire:model="reverseReason" rows="2" placeholder="سبب العكس" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        @error('reverseReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showReverse=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="confirmReverse" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white">تأكيد العكس</button></div>
    </x-modal>
</div>
