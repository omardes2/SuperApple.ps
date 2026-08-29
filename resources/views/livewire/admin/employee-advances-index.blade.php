<div>
    <x-page-header title="سلف الموظفين" subtitle="سلف وقروض تُسترد من الرواتب — بالشيكل (ILS)">
        <x-slot:actions>@if ($canCreate)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ سلفة</button>@endif</x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-5"><x-stat-card label="إجمالي السلف القائمة" :value="number_format((float) $outstanding, 2).' ₪'" hint="مستحقة الاسترداد" icon="wallet" tone="amber" /></div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الموظف</th><th class="px-4 py-3">النوع</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">المتبقي</th><th class="px-4 py-3">القسط</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">إجراءات</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($advances as $adv)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $adv->advance_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $adv->employee?->full_name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $adv->type->label() }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $adv->amount_ils, 2) }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $adv->remaining_ils, 2) }}</td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $adv->installment_ils ? number_format((float) $adv->installment_ils, 2) : '—' }}</td>
                        <td class="px-4 py-3"><x-badge :class="$adv->status->badgeClass()">{{ $adv->status->label() }}</x-badge></td>
                        <td class="px-4 py-3">
                            <div class="flex gap-1">
                                @if ($canApprove && $adv->status->value === 'draft')<button wire:click="approve({{ $adv->id }})" class="rounded border border-blue-300 px-2 py-1 text-xs text-blue-700">اعتماد</button>@endif
                                @if ($canPay && $adv->status->value === 'approved')<button wire:click="pay({{ $adv->id }})" class="rounded border border-emerald-300 px-2 py-1 text-xs text-emerald-700">دفع</button>@endif
                                @if ($canManage && ! in_array($adv->status->value, ['cancelled','recovered','partially_recovered']))<button wire:click="cancel({{ $adv->id }})" wire:confirm="إلغاء السلفة؟" class="rounded border border-red-200 px-2 py-1 text-xs text-red-600">إلغاء</button>@endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا سلف.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $advances->links() }}</div>

    <x-modal show="showCreate" title="سلفة / قرض جديد">
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">الموظف</label><select wire:model="employee_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">— اختر —</option>@foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach</select>@error('employee_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">النوع</label><select wire:model="type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="advance">سلفة</option><option value="loan">قرض</option></select></div>
                <div><label class="mb-1 block text-sm text-slate-600">المبلغ</label><input type="number" step="0.01" wire:model="amount_ils" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('amount_ils')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">قسط الاسترداد الشهري (اختياري)</label><input type="number" step="0.01" wire:model="installment_ils" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            <div><label class="mb-1 block text-sm text-slate-600">الحساب النقدي (عند الدفع)</label><select wire:model="financial_account_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showCreate=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="save" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>
</div>
