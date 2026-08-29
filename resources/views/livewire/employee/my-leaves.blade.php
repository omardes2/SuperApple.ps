<div class="space-y-5">
    <x-page-header title="إجازاتي" subtitle="قدّم طلبات الإجازة وتابع حالتها">
        <x-slot:actions>
            @can('leaves.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ طلب إجازة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">النوع</th><th class="px-4 py-3">من</th><th class="px-4 py-3">إلى</th><th class="px-4 py-3">أيام</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">إجراء</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($requests as $req)
                    <tr>
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $req->reference_no }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $req->leaveType->name }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $req->start_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $req->end_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $req->total_days }}</td>
                        <td class="px-4 py-3"><x-badge :class="$req->status->badgeClass()">{{ $req->status->label() }}</x-badge></td>
                        <td class="px-4 py-3">
                            @if ($req->status === \App\Enums\LeaveStatus::Pending)
                                <button wire:click="cancel({{ $req->id }})" class="text-red-600 hover:underline">إلغاء</button>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا طلبات إجازة بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $requests->links() }}</div>

    <x-modal show="showForm" title="طلب إجازة جديد">
        <form wire:submit="submit" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">نوع الإجازة</label>
                <select wire:model="leave_type_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— اختر —</option>
                    @foreach ($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('leave_type_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">من تاريخ</label>
                    <input type="date" wire:model="start_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('start_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">إلى تاريخ</label>
                    <input type="date" wire:model="end_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('end_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">السبب</label>
                <textarea wire:model="reason" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">مرفق (اختياري)</label>
                <input type="file" wire:model="attachment" class="w-full text-sm">
                @error('attachment') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" wire:loading.attr="disabled">تقديم</button>
            </div>
        </form>
    </x-modal>
</div>
