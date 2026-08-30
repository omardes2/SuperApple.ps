<div>
    <x-page-header title="الإجازات" subtitle="مراجعة واعتماد طلبات الإجازات">
        <x-slot:actions>
            @if ($canManageTypes)
                <button wire:click="openTypes" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">أنواع الإجازات</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث عن موظف..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الموظف</th>
                    <th class="px-4 py-3">النوع</th><th class="px-4 py-3">من</th><th class="px-4 py-3">إلى</th>
                    <th class="px-4 py-3">أيام</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($leaves as $lv)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $lv->reference_no }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $lv->employee->full_name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $lv->leaveType->name }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $lv->start_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $lv->end_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $lv->total_days }}</td>
                        <td class="px-4 py-3"><x-badge :class="$lv->status->badgeClass()">{{ $lv->status->label() }}</x-badge></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                @if ($lv->status === \App\Enums\LeaveStatus::Pending)
                                    @can('leaves.approve')<button wire:click="openReview({{ $lv->id }}, 'approve')" class="text-emerald-600 hover:underline">اعتماد</button>@endcan
                                    @can('leaves.reject')<button wire:click="openReview({{ $lv->id }}, 'reject')" class="text-red-600 hover:underline">رفض</button>@endcan
                                @elseif ($lv->status === \App\Enums\LeaveStatus::Approved)
                                    @can('leaves.manage')<button wire:click="openReview({{ $lv->id }}, 'reverse')" class="text-amber-600 hover:underline">عكس/إلغاء</button>@endcan
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا طلبات إجازة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $leaves->links() }}</div>

    <x-modal show="showReview" title="مراجعة طلب الإجازة">
        <form wire:submit="confirmReview" class="space-y-4">
            <p class="text-sm text-slate-600">
                @if ($reviewAction === 'approve') سيتم اعتماد الطلب وتسجيل أيامه كإجازة في الدوام.
                @elseif ($reviewAction === 'reject') سيتم رفض الطلب دون التأثير على الدوام.
                @else سيتم عكس الإجازة المعتمدة وإزالة أيامها من الدوام مع حفظ السجل.
                @endif
            </p>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات (اختياري)</label>
                <textarea wire:model="reviewNotes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('reviewNotes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showReview = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">تأكيد</button>
            </div>
        </form>
    </x-modal>

    @if ($canManageTypes)
        <x-modal show="showTypes" title="أنواع الإجازات" maxWidth="max-w-2xl">
            <div class="mb-3 flex justify-end">
                <button wire:click="newType" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">+ نوع جديد</button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                        <tr><th class="px-3 py-2">الاسم</th><th class="px-3 py-2">الرمز</th><th class="px-3 py-2">النوع</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($leaveTypes as $type)
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $type->name }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-slate-500" dir="ltr">{{ $type->code }}</td>
                                <td class="px-3 py-2">
                                    @if ($type->is_paid)<x-badge class="bg-emerald-50 text-emerald-700">مدفوعة</x-badge>@else<x-badge class="bg-slate-100 text-slate-600">غير مدفوعة</x-badge>@endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($type->is_active)<x-badge class="bg-emerald-50 text-emerald-700">نشط</x-badge>@else<x-badge class="bg-red-50 text-red-700">غير نشط</x-badge>@endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex gap-1">
                                        <button wire:click="editType({{ $type->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600">تعديل</button>
                                        <button wire:click="toggleTypeActive({{ $type->id }})" class="rounded border px-2 py-1 text-xs {{ $type->is_active ? 'border-red-200 text-red-600' : 'border-emerald-200 text-emerald-700' }}">{{ $type->is_active ? 'تعطيل' : 'تفعيل' }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-slate-400">لا أنواع إجازات. أضف نوعاً جديداً.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($showTypeForm)
                <div class="mt-4 rounded-lg border border-brand-200 bg-brand-50/40 p-4">
                    <h4 class="mb-3 text-sm font-semibold text-slate-800">{{ $typeId ? 'تعديل نوع' : 'نوع جديد' }}</h4>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm text-slate-600">الاسم</label>
                            <input wire:model="typeName" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('typeName')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm text-slate-600">الرمز</label>
                            <input wire:model="typeCode" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('typeCode')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-5">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="typeIsPaid"> مدفوعة</label>
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="typeRequiresAttachment"> تتطلب مرفقاً</label>
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="typeIsActive"> نشط</label>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button wire:click="$set('showTypeForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button>
                        <button wire:click="saveType" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button>
                    </div>
                </div>
            @endif

            <div class="mt-4 flex justify-end border-t border-slate-100 pt-4">
                <button @click="$wire.showTypes = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">إغلاق</button>
            </div>
        </x-modal>
    @endif
</div>
