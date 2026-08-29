<div>
    <x-page-header :title="'مصروف '.$expense->expense_number" subtitle="القيمة المحاسبية بالشيكل">
        <x-slot:actions>
            <a href="{{ route('admin.expenses') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
            @can('cancel', $expense)@unless ($expense->isCancelled())<button wire:click="openCancel" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">إلغاء</button>@endunless @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-4"><x-badge :class="$expense->status->badgeClass()">{{ $expense->status->label() }}</x-badge></div>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div><label class="mb-1 block text-sm text-slate-600">التاريخ</label><input type="date" wire:model="expense_date" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></div>
            <div><label class="mb-1 block text-sm text-slate-600">الفئة</label>
                <select wire:model="category_id" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>@error('category_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2"><label class="mb-1 block text-sm text-slate-600">الوصف</label><input type="text" wire:model="description" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">@error('description')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm text-slate-600">العملة</label>
                <select wire:model.live="currency" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"><option value="ILS">شيكل (ILS)</option><option value="USD">دولار (USD)</option></select>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">المبلغ</label><input type="number" step="0.01" wire:model.live="amount" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">@error('amount')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            @if ($currency === 'USD')
                <div><label class="mb-1 block text-sm text-slate-600">سعر الصرف</label><input type="number" step="0.000001" wire:model.live="exchange_rate" @disabled(!$canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">@error('exchange_rate')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm text-slate-600">ما يعادله ILS</label><div class="rounded-lg bg-slate-50 px-3 py-2 text-sm" dir="ltr">{{ number_format((float) $amountIls, 2) }} ₪</div></div>
            @endif
            <div><label class="mb-1 block text-sm text-slate-600">الحساب النقدي/البنكي</label>
                <select wire:model="financial_account_id" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    <option value="">— اختر —</option>
                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->currency }})</option>@endforeach
                </select>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">المورد (اختياري)</label>
                <select wire:model="supplier_id" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">المشروع (اختياري)</label>
                <select wire:model="project_id" @disabled(!$canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"><option value="">—</option>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
            </div>
        </div>

        @if ($canEdit || $canPost)
            <div class="mt-4 flex justify-end gap-2">
                @if ($canEdit)<button wire:click="save" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">حفظ</button>@endif
                @if ($canPost)<button wire:click="post" wire:confirm="سيتم ترحيل المصروف محاسبياً. متابعة؟" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">ترحيل</button>@endif
            </div>
        @endif
    </div>

    <x-modal show="showCancel" title="إلغاء المصروف">
        <label class="mb-1 block text-sm text-slate-600">سبب الإلغاء</label>
        <textarea wire:model="cancelReason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        @error('cancelReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="$wire.showCancel = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button>
            <button wire:click="confirmCancel" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">تأكيد</button>
        </div>
    </x-modal>
</div>
