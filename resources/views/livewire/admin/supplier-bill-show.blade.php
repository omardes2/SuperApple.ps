<div>
    <x-page-header :title="'فاتورة مورد '.$bill->bill_number" :subtitle="$bill->supplier->name">
        <x-slot:actions>
            <a href="{{ route('admin.suppliers.show', $bill->supplier) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
            @can('cancel', $bill)@unless ($bill->isCancelled())<button wire:click="openCancel" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">إلغاء</button>@endunless @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-4"><x-badge :class="$bill->status->badgeClass()">{{ $bill->status->label() }}</x-badge></div>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div><label class="mb-1 block text-sm text-slate-600">تاريخ الفاتورة</label><input type="date" wire:model="bill_date" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
            <div><label class="mb-1 block text-sm text-slate-600">الاستحقاق</label><input type="date" wire:model="due_date" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
            <div><label class="mb-1 block text-sm text-slate-600">المرجع</label><input type="text" wire:model="reference_number" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
            <div><label class="mb-1 block text-sm text-slate-600">العملة</label>
                <select wire:model.live="currency" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"><option value="ILS">شيكل (ILS)</option><option value="USD">دولار (USD)</option></select>
            </div>
            @if ($currency === 'USD')
                <div><label class="mb-1 block text-sm text-slate-600">سعر الصرف</label>
                    <div class="flex gap-2"><input type="number" step="0.000001" wire:model="exchange_rate" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">@if($canEdit)<button wire:click="suggestRate" class="rounded-lg border border-slate-300 px-3 text-xs text-slate-600">اقتراح</button>@endif</div>
                    @error('exchange_rate')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
        </div>

        <h3 class="mb-2 mt-5 font-semibold text-slate-700">البنود</h3>
        @error('items')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="space-y-2">
            @foreach ($items as $i => $item)
                <div class="flex flex-col gap-2 rounded-lg border border-slate-200 p-3 sm:flex-row" wire:key="item-{{ $i }}">
                    <input type="text" wire:model="items.{{ $i }}.description" @disabled(!$canEdit) placeholder="الوصف" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    <input type="number" step="0.01" wire:model.live="items.{{ $i }}.quantity" @disabled(!$canEdit) placeholder="كمية" dir="ltr" class="w-20 rounded-lg border border-slate-300 px-2 py-2 text-sm disabled:bg-slate-50">
                    <input type="number" step="0.01" wire:model.live="items.{{ $i }}.unit_price" @disabled(!$canEdit) placeholder="سعر" dir="ltr" class="w-24 rounded-lg border border-slate-300 px-2 py-2 text-sm disabled:bg-slate-50">
                    <input type="number" step="0.01" wire:model.live="items.{{ $i }}.tax" @disabled(!$canEdit) placeholder="ضريبة" dir="ltr" class="w-20 rounded-lg border border-slate-300 px-2 py-2 text-sm disabled:bg-slate-50">
                    <select wire:model="items.{{ $i }}.expense_account_id" @disabled(!$canEdit) class="rounded-lg border border-slate-300 px-2 py-2 text-sm disabled:bg-slate-50">
                        <option value="">حساب المصروف</option>
                        @foreach ($expenseAccounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach
                    </select>
                    @if ($canEdit)<button wire:click="removeItem({{ $i }})" class="rounded-lg border border-red-200 px-2 text-xs text-red-600">حذف</button>@endif
                </div>
            @endforeach
        </div>
        @if ($canEdit)<button wire:click="addItem" class="mt-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600">+ بند</button>@endif

        <div class="mt-4 rounded-lg bg-slate-50 p-3 text-center"><span class="text-sm text-slate-500">الإجمالي: </span><span class="font-bold" dir="ltr">{{ number_format((float) $total, 2) }} {{ $currency }}</span></div>

        @if ($canEdit || $canPost)
            <div class="mt-4 flex justify-end gap-2">
                @if ($canEdit)<button wire:click="save" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">حفظ</button>@endif
                @if ($canPost)<button wire:click="post" wire:confirm="سيتم ترحيل الفاتورة في الذمم الدائنة. متابعة؟" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">ترحيل</button>@endif
            </div>
        @endif
    </div>

    <x-modal show="showCancel" title="إلغاء فاتورة المورد">
        <label class="mb-1 block text-sm text-slate-600">سبب الإلغاء</label>
        <textarea wire:model="cancelReason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        @error('cancelReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showCancel = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="confirmCancel" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">تأكيد</button></div>
    </x-modal>
</div>
