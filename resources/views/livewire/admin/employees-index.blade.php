<div>
    <x-page-header title="الموظفون" subtitle="ملفات الموظفين التشغيلية (لا تتضمن أي بيانات مالية)">
        <x-slot:actions>
            @can('employees.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ موظف جديد</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="إجمالي الموظفين" :value="$stats['total']" icon="badge" tone="brand" />
        <x-stat-card label="النشطون" :value="$stats['active']" icon="users" tone="emerald" />
        <x-stat-card label="الحاضرون اليوم" :value="$stats['present']" icon="clock" tone="violet" />
        <x-stat-card label="الغائبون اليوم" :value="$stats['absent']" icon="minus" tone="amber" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم/الرقم/الهاتف..."
               class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
        <select wire:model.live="department" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الأقسام</option>
            @foreach ($departments as $d)
                <option value="{{ $d->id }}">{{ $d->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)
                <option value="{{ $val }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الأنواع</option>
            @foreach ($typeOptions as $val => $label)
                <option value="{{ $val }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th>
                    <th class="px-4 py-3">الاسم</th>
                    <th class="px-4 py-3">القسم</th>
                    <th class="px-4 py-3">المسمى</th>
                    <th class="px-4 py-3">المدير</th>
                    <th class="px-4 py-3">الحالة</th>
                    <th class="px-4 py-3">التوظيف</th>
                    <th class="px-4 py-3">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($employees as $employee)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $employee->employee_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">
                            <a href="{{ route('admin.employees.show', $employee) }}" class="hover:text-brand-600 hover:underline">{{ $employee->full_name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $employee->department?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $employee->job_title ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $employee->directManager?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3"><x-badge :class="$employee->employment_status->badgeClass()">{{ $employee->employment_status->label() }}</x-badge></td>
                        <td class="px-4 py-3 text-slate-500">{{ $employee->employment_type->label() }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.employees.show', $employee) }}" class="text-brand-600 hover:underline">الملف</a>
                            @can('employees.edit')
                                <button wire:click="edit({{ $employee->id }})" class="mr-2 text-slate-500 hover:underline">تعديل</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا يوجد موظفون مطابقون.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $employees->links() }}</div>

    <x-modal show="showForm" :title="$editingId ? 'تعديل موظف' : 'موظف جديد'" maxWidth="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الاسم الكامل</label>
                    <input type="text" wire:model="full_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('full_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الرقم الوظيفي</label>
                    <input type="text" wire:model="employee_number" dir="ltr" placeholder="تلقائي إن ترك فارغاً" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('employee_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الهاتف</label>
                    <input type="text" wire:model="phone" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">المسمى الوظيفي</label>
                    <input type="text" wire:model="job_title" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">القسم</label>
                    <select wire:model="department_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— بدون —</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">المدير المباشر</label>
                    <select wire:model="direct_manager_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— بدون —</option>
                        @foreach ($managers as $m)
                            @if ($m->id !== $editingId)
                                <option value="{{ $m->id }}">{{ $m->full_name }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('direct_manager_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ التوظيف</label>
                    <input type="date" wire:model="hire_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">ساعات العمل اليومية</label>
                    <input type="number" step="0.5" wire:model="working_hours_per_day" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">حالة التوظيف</label>
                    <select wire:model="employment_status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($statusOptions as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">نوع التوظيف</label>
                    <select wire:model="employment_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($typeOptions as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات HR</label>
                    <textarea wire:model="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> موظف نشط
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
