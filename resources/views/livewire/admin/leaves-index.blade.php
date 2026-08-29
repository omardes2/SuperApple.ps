<div>
    <x-page-header title="الإجازات" subtitle="مراجعة واعتماد طلبات الإجازات" />

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
</div>
