<div>
    <div class="mb-5 flex items-center gap-4">
        <a href="{{ route('admin.employees.show', $employee) }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></a>
        <div>
            <h2 class="text-xl font-bold text-slate-800">{{ $employee->full_name }} — الرواتب والسلف</h2>
            <p class="text-sm text-slate-500" dir="ltr">{{ $employee->employee_number }}</p>
        </div>
        <div class="mr-auto flex gap-2">
            @if ($canManageSalary)<button wire:click="openSalary" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">تحديد راتب</button>@endif
            @if ($canManageAdjustments)<button wire:click="openAdjustment" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">+ تعديل</button>@endif
        </div>
    </div>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-stat-card label="الراتب الحالي" :value="$currentSalary ? number_format((float) $currentSalary->base_salary_ils, 2).' ₪' : '—'" hint="شهري" icon="wallet" tone="brand" />
        <x-stat-card label="السلف القائمة" :value="number_format((float) $advances->whereIn('status', ['paid','partially_recovered'])->sum('remaining_ils'), 2).' ₪'" icon="repeat" tone="amber" />
        <x-stat-card label="عدد المسيرات" :value="$payrollItems->count()" icon="doc" tone="slate" />
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">سجل الرواتب</h3>
            <table class="min-w-full text-sm">
                <thead class="text-right text-xs text-slate-500"><tr><th class="py-1">من</th><th class="py-1">إلى</th><th class="py-1">الراتب</th></tr></thead>
                <tbody>
                    @forelse ($profiles as $p)
                        <tr class="border-t border-slate-100"><td class="py-1" dir="ltr">{{ $p->effective_from->format('Y-m-d') }}</td><td class="py-1" dir="ltr">{{ $p->effective_to?->format('Y-m-d') ?? '—' }}</td><td class="py-1 font-medium" dir="ltr">{{ number_format((float) $p->base_salary_ils, 2) }}</td></tr>
                    @empty<tr><td colspan="3" class="py-3 text-slate-400">لا سجل رواتب.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">التعديلات</h3>
            <table class="min-w-full text-sm">
                <thead class="text-right text-xs text-slate-500"><tr><th class="py-1">النوع</th><th class="py-1">الفئة</th><th class="py-1">المبلغ</th><th class="py-1">متكرر</th></tr></thead>
                <tbody>
                    @forelse ($adjustments as $a)
                        <tr class="border-t border-slate-100"><td class="py-1">{{ $a->adjustment_type->label() }}</td><td class="py-1 text-slate-500">{{ $a->category }}</td><td class="py-1 {{ $a->isEarning() ? 'text-emerald-700' : 'text-red-600' }}" dir="ltr">{{ number_format((float) $a->amount_ils, 2) }}</td><td class="py-1 text-xs text-slate-400">{{ $a->is_recurring ? 'نعم' : 'لا' }}</td></tr>
                    @empty<tr><td colspan="4" class="py-3 text-slate-400">لا تعديلات.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal show="showSalary" title="تحديد الراتب">
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">الراتب الأساسي (شهري)</label><input type="number" step="0.01" wire:model="base_salary_ils" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('base_salary_ils')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm text-slate-600">ساري اعتباراً من</label><input type="date" wire:model="effective_from" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="mb-1 block text-sm text-slate-600">معدل ساعة إضافية (اختياري)</label><input type="number" step="0.01" wire:model="overtime_rate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showSalary=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="saveSalary" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>

    <x-modal show="showAdjustment" title="تعديل راتب">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">النوع</label><select wire:model.live="adjustment_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="earning">استحقاق</option><option value="deduction">استقطاع</option></select></div>
                <div><label class="mb-1 block text-sm text-slate-600">الفئة</label>
                    <select wire:model="category" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="bonus">مكافأة</option><option value="commission">عمولة</option><option value="allowance">بدل</option><option value="penalty">جزاء</option><option value="other">أخرى</option>
                    </select>
                </div>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">المبلغ</label><input type="number" step="0.01" wire:model="adjustment_amount" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('adjustment_amount')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            @if ($adjustment_type === 'deduction')
                <div><label class="mb-1 block text-sm text-slate-600">حساب الاستقطاع (اختياري)</label><select wire:model="gl_account_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">افتراضي (استقطاعات رواتب)</option>@foreach ($deductionAccounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach</select></div>
            @endif
            <div><label class="mb-1 block text-sm text-slate-600">الوصف</label><input type="text" wire:model="adjustment_desc" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" wire:model="is_recurring"> متكرر شهرياً</label>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showAdjustment=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="saveAdjustment" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">إضافة</button></div>
    </x-modal>
</div>
