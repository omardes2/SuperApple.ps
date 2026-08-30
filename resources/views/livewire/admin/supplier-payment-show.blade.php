<div>
    <x-page-header :title="'دفعة مورد '.$payment->payment_number" :subtitle="$payment->supplier->name">
        <x-slot:actions>
            <a href="{{ route('admin.suppliers.show', $payment->supplier) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
            @can('cancel', $payment)@unless ($payment->isCancelled())<button wire:click="openCancel" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">إلغاء</button>@endunless @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-4"><x-badge :class="$payment->status->badgeClass()">{{ $payment->status->label() }}</x-badge></div>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div><label class="mb-1 block text-sm text-slate-600">التاريخ</label><input type="date" wire:model="payment_date" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
            <div><label class="mb-1 block text-sm text-slate-600">العملة</label>
                <select wire:model.live="currency" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"><option value="ILS">شيكل (ILS)</option><option value="USD">دولار (USD)</option></select>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">المبلغ</label><input type="number" step="0.01" wire:model.live="amount" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">@error('amount')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            @if ($currency === 'USD')
                <div><label class="mb-1 block text-sm text-slate-600">سعر الصرف</label>
                    <div class="flex gap-2"><input type="number" step="0.000001" wire:model="exchange_rate" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
                </div>
            @endif
            <div><label class="mb-1 block text-sm text-slate-600">الحساب النقدي/البنكي</label>
                <select wire:model="financial_account_id" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    <option value="">— اختر —</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                </select>@error('financial_account_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">المرجع</label><input type="text" wire:model="reference_number" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
        </div>

        @if ($payment->isDraft())
            <div class="mt-5 mb-2 flex items-center justify-between">
                <h3 class="font-semibold text-slate-700">تخصيص على فواتير المورد</h3>
                <div class="flex gap-2">
                    <button wire:click="autoAllocate" class="rounded-lg border border-brand-300 px-3 py-1.5 text-xs text-brand-700">تخصيص تلقائي</button>
                    <button wire:click="addAllocation" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600">+ سطر</button>
                </div>
            </div>
            @if ($openBills->isEmpty())
                <p class="text-sm text-slate-400">لا فواتير مفتوحة بعملة {{ $currency }} لهذا المورد.</p>
            @else
                <div class="space-y-2">
                    @foreach ($allocations as $i => $row)
                        <div class="flex gap-2" wire:key="salloc-{{ $i }}">
                            <select wire:model.live="allocations.{{ $i }}.bill_id" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">— فاتورة —</option>
                                @foreach ($openBills as $bill)<option value="{{ $bill->id }}">{{ $bill->bill_number }} — متبقٍ {{ number_format((float) $bill->remaining_original, 2) }} {{ $bill->currency }}</option>@endforeach
                            </select>
                            <input type="number" step="0.01" wire:model.live="allocations.{{ $i }}.allocated_original" dir="ltr" class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <button wire:click="removeAllocation({{ $i }})" class="rounded-lg border border-red-200 px-3 text-xs text-red-600">حذف</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-3 text-center text-sm">
                    <div><p class="text-xs text-slate-500">المبلغ</p><p class="font-bold" dir="ltr">{{ number_format((float) $amount, 2) }}</p></div>
                    <div><p class="text-xs text-slate-500">المخصّص</p><p class="font-bold" dir="ltr">{{ number_format((float) $allocatedTotal, 2) }}</p></div>
                    <div><p class="text-xs text-slate-500">المتبقي</p><p class="font-bold {{ (float) $remaining != 0 ? 'text-amber-600' : 'text-emerald-700' }}" dir="ltr">{{ number_format((float) $remaining, 2) }}</p></div>
                </div>
            @endif
        @else
            <div class="mt-5">
                <h3 class="mb-2 font-semibold text-slate-700">التخصيصات وفروقات الصرف</h3>
                <table class="min-w-full text-sm">
                    <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الفاتورة</th><th class="py-2">المخصّص</th><th class="py-2">فرق الصرف ILS</th><th class="py-2">الحالة</th></tr></thead>
                    <tbody>
                        @foreach ($payment->allocations as $a)
                            <tr class="{{ $a->status === 'reversed' ? 'text-slate-400 line-through' : '' }}"><td class="py-1 font-mono" dir="ltr">{{ $a->bill?->bill_number }}</td><td class="py-1" dir="ltr">{{ number_format((float) $a->allocated_original, 2) }}</td><td class="py-1 {{ (float) $a->exchange_difference_ils > 0 ? 'text-emerald-700' : ((float) $a->exchange_difference_ils < 0 ? 'text-red-600' : '') }}" dir="ltr">{{ number_format((float) $a->exchange_difference_ils, 2) }}</td><td class="py-1 text-xs">{{ $a->status === 'reversed' ? 'معكوس' : 'نشط' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($canEdit || $canPost)
            <div class="mt-4 flex justify-end gap-2">
                @if ($canEdit)<button wire:click="save" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">حفظ</button>@endif
                @if ($canPost)<button wire:click="post" wire:confirm="سيتم ترحيل الدفعة. متابعة؟" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">ترحيل</button>@endif
            </div>
        @endif
    </div>

    <x-modal show="showCancel" title="إلغاء دفعة المورد">
        <label class="mb-1 block text-sm text-slate-600">سبب الإلغاء</label>
        <textarea wire:model="cancelReason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        @error('cancelReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showCancel = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="confirmCancel" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">تأكيد</button></div>
    </x-modal>
</div>
