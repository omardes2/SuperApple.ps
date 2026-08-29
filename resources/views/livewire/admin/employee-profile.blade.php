<div>
    <div class="mb-5 flex items-center gap-4">
        <a href="{{ route('admin.employees') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <div class="flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-100 text-lg font-bold text-brand-700">{{ mb_substr($employee->full_name, 0, 1) }}</span>
            <div>
                <h2 class="text-xl font-bold text-slate-800">{{ $employee->full_name }}</h2>
                <p class="text-sm text-slate-500">{{ $employee->job_title ?? '—' }} · {{ $employee->department?->name ?? 'بدون قسم' }}</p>
            </div>
        </div>
        <div class="mr-auto flex items-center gap-2">
            @can('salary_profiles.view')
                <a href="{{ route('admin.employees.payroll', $employee) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">الرواتب والسلف</a>
            @endcan
            <x-badge :class="$employee->employment_status->badgeClass()">{{ $employee->employment_status->label() }}</x-badge>
        </div>
    </div>

    @php
        $tabs = [
            'overview' => 'نظرة عامة',
            'attendance' => 'الدوام',
            'leaves' => 'الإجازات',
            'tasks' => 'المهام',
            'projects' => 'المشاريع',
            'documents' => 'المستندات',
            'activity' => 'سجل النشاط',
        ];
    @endphp
    <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="setTab('{{ $key }}')"
                    class="shrink-0 border-b-2 px-4 py-2.5 text-sm transition {{ $tab === $key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Overview --}}
    @if ($tab === 'overview')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">المعلومات الوظيفية</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">الرقم الوظيفي</dt><dd class="font-mono text-slate-700" dir="ltr">{{ $employee->employee_number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الهاتف</dt><dd class="text-slate-700" dir="ltr">{{ $employee->phone ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">القسم</dt><dd class="text-slate-700">{{ $employee->department?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المدير المباشر</dt><dd class="text-slate-700">{{ $employee->directManager?->full_name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">تاريخ التوظيف</dt><dd class="text-slate-700" dir="ltr">{{ $employee->hire_date?->format('Y-m-d') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">نوع التوظيف</dt><dd class="text-slate-700">{{ $employee->employment_type->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">ساعات العمل اليومية</dt><dd class="text-slate-700" dir="ltr">{{ $employee->working_hours_per_day }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">حساب الدخول</dt><dd class="text-slate-700" dir="ltr">{{ $employee->user?->email ?? 'غير مرتبط' }}</dd></div>
                </dl>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">ملاحظات</h3>
                <p class="text-sm text-slate-600">{{ $employee->notes ?: 'لا توجد ملاحظات.' }}</p>
                <p class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-400">لا تظهر أي بيانات مالية أو رواتب في هذا الملف. الرواتب في وحدة مستقلة تتطلب صلاحية payroll.view.</p>
            </div>
        </div>
    @endif

    {{-- Attendance --}}
    @if ($tab === 'attendance')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الحضور</th><th class="px-4 py-3">الانصراف</th><th class="px-4 py-3">ساعات</th><th class="px-4 py-3">تأخير</th><th class="px-4 py-3">الحالة</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($attendance as $rec)
                        <tr>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->attendance_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->check_in_at?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->check_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->workedHoursLabel() }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->late_minutes }} د</td>
                            <td class="px-4 py-3"><x-badge :class="$rec->status->badgeClass()">{{ $rec->status->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">لا سجلات دوام.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- Leaves --}}
    @if ($tab === 'leaves')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">النوع</th><th class="px-4 py-3">من</th><th class="px-4 py-3">إلى</th><th class="px-4 py-3">أيام</th><th class="px-4 py-3">الحالة</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($leaves as $lv)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $lv->reference_no }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $lv->leaveType->name }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $lv->start_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $lv->end_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $lv->total_days }}</td>
                            <td class="px-4 py-3"><x-badge :class="$lv->status->badgeClass()">{{ $lv->status->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">لا طلبات إجازة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- Tasks / Projects placeholders (Sprint 2) --}}
    @if ($tab === 'tasks')
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-400">تُعرض مهام الموظف بعد تنفيذ وحدة المهام (Sprint 2).</div>
    @endif
    @if ($tab === 'projects')
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-400">تُعرض مشاريع الموظف بعد تنفيذ وحدة المشاريع (Sprint 2).</div>
    @endif

    {{-- Documents --}}
    @if ($tab === 'documents')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                        <tr><th class="px-4 py-3">المستند</th><th class="px-4 py-3">النوع</th><th class="px-4 py-3">تاريخ الرفع</th><th class="px-4 py-3">بواسطة</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($documents as $doc)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-700">{{ $doc->title }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ $doc->type ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $doc->created_at->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ $doc->uploader?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا مستندات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('employees.documents')
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="mb-3 font-semibold text-slate-800">رفع مستند</h3>
                    <form wire:submit="addDocument" class="space-y-3">
                        <input type="text" wire:model="docTitle" placeholder="اسم المستند" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('docTitle') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <input type="text" wire:model="docType" placeholder="النوع (عقد، هوية...)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <textarea wire:model="docNotes" rows="2" placeholder="ملاحظات" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                        <input type="file" wire:model="docFile" class="w-full text-sm">
                        @error('docFile') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" wire:loading.attr="disabled">رفع</button>
                    </form>
                </div>
            @endcan
        </div>
    @endif

    {{-- Activity --}}
    @if ($tab === 'activity')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">العملية</th><th class="px-4 py-3">بواسطة</th><th class="px-4 py-3">الوصف</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($activity as $log)
                        <tr>
                            <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3"><x-badge>{{ $log->action }}</x-badge></td>
                            <td class="px-4 py-3 text-slate-600">{{ $log->user?->name ?? 'النظام' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $log->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا نشاط مسجل.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
