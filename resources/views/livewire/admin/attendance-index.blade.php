<div>
    <x-page-header title="الدوام" subtitle="متابعة حضور الموظفين اليومي" />

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="الحاضرون" :value="$stats['present']" icon="clock" tone="emerald" />
        <x-stat-card label="المتأخرون" :value="$stats['late']" icon="clock" tone="amber" />
        <x-stat-card label="الغائبون" :value="$stats['absent']" icon="minus" tone="red" />
        <x-stat-card label="في إجازة" :value="$stats['on_leave']" icon="calendar" tone="brand" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="date" wire:model.live="date" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث عن موظف..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="department" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الأقسام</option>
            @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الموظف</th><th class="px-4 py-3">القسم</th>
                    <th class="px-4 py-3">الحضور</th><th class="px-4 py-3">الانصراف</th>
                    <th class="px-4 py-3">ساعات</th><th class="px-4 py-3">تأخير</th>
                    <th class="px-4 py-3">إضافي</th><th class="px-4 py-3">الحالة</th>
                    @can('attendance.adjust')<th class="px-4 py-3">إجراءات</th>@endcan
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($records as $rec)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $rec->employee->full_name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $rec->employee->department?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->check_in_at?->format('H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->check_out_at?->format('H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->workedHoursLabel() }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->late_minutes }} د</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->overtime_minutes }} د</td>
                        <td class="px-4 py-3"><x-badge :class="$rec->status->badgeClass()">{{ $rec->status->label() }}</x-badge></td>
                        @can('attendance.adjust')
                            <td class="px-4 py-3"><button wire:click="openAdjust({{ $rec->id }})" class="text-brand-600 hover:underline">تعديل</button></td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">لا سجلات دوام لهذا اليوم.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $records->links() }}</div>

    <x-modal show="showAdjust" title="تعديل سجل الدوام">
        <form wire:submit="saveAdjust" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">وقت الحضور</label>
                    <input type="datetime-local" wire:model="adjCheckIn" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('adjCheckIn') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">وقت الانصراف</label>
                    <input type="datetime-local" wire:model="adjCheckOut" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('adjCheckOut') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الحالة</label>
                    <select wire:model="adjStatus" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات</label>
                    <textarea wire:model="adjNotes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
            </div>
            <p class="rounded-lg bg-amber-50 p-2 text-xs text-amber-700">سيتم تسجيل هذا التعديل في سجل العمليات وإشعار الموظف.</p>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showAdjust = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ التعديل</button>
            </div>
        </form>
    </x-modal>
</div>
