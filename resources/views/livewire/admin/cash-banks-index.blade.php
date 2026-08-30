<div>
    <x-page-header title="الصندوق والبنوك" subtitle="الأرصدة مشتقة من الأستاذ العام (ILS)">
        <x-slot:actions>
            @can('financial_accounts.manage')
                <button wire:click="openTransfer" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">تحويل</button>
                <button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ حساب</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('lifecycle')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($rows as $r)
            <div class="rounded-xl border border-slate-200 bg-white p-5 {{ $r['account']->is_active ? '' : 'opacity-70' }}">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm text-slate-500">{{ $r['account']->name }}</p>
                        <p class="mt-1 text-2xl font-bold text-slate-800" dir="ltr">{{ number_format((float) $r['balance_original'], 2) }} {{ $r['account']->currency }}</p>
                        @if ($r['account']->currency !== 'ILS')<p class="text-xs text-slate-400" dir="ltr">≈ {{ number_format((float) $r['balance_ils'], 2) }} ₪</p>@endif
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <x-badge class="bg-slate-100 text-slate-600">{{ $r['account']->type->label() }}</x-badge>
                        @if ($r['account']->is_active)
                            <x-badge class="bg-emerald-50 text-emerald-700">نشط</x-badge>
                        @else
                            <x-badge class="bg-red-50 text-red-700">معطّل</x-badge>
                        @endif
                        @if ($r['is_default'])<x-badge class="bg-amber-50 text-amber-700">افتراضي</x-badge>@endif
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs font-medium">
                    <button wire:click="showStatement({{ $r['account']->id }})" class="text-brand-600 hover:underline">كشف الحساب ←</button>
                    @can('financial_accounts.manage')
                        @if ($r['account']->is_active)
                            <button wire:click="confirm('deactivate', {{ $r['account']->id }})" class="text-amber-700 hover:underline">تعطيل</button>
                        @else
                            <button wire:click="confirm('activate', {{ $r['account']->id }})" class="text-emerald-700 hover:underline">تفعيل</button>
                        @endif
                        @if ($r['can_delete'])
                            <button wire:click="confirm('delete', {{ $r['account']->id }})" class="text-red-600 hover:underline">حذف</button>
                        @endif
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
    <p class="mb-6 text-sm text-slate-500">إجمالي النقد (بالقيمة المحاسبية): <span class="font-bold" dir="ltr">{{ number_format((float) $totalIls, 2) }} ₪</span></p>

    @if ($statement)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-700">كشف حساب: {{ $statement['account']->name }}</div>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">القيد</th><th class="px-4 py-3">البيان</th><th class="px-4 py-3">مدين</th><th class="px-4 py-3">دائن</th><th class="px-4 py-3">الرصيد</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($statement['ledger']['rows'] as $row)
                        <tr><td class="px-4 py-2 text-slate-600" dir="ltr">{{ $row['entry']->entry_date->format('Y-m-d') }}</td><td class="px-4 py-2 font-mono text-slate-500" dir="ltr">{{ $row['entry']->journal_number }}</td><td class="px-4 py-2 text-slate-600">{{ $row['line']->description }}</td><td class="px-4 py-2" dir="ltr">{{ (float) $row['line']->debit_ils ? number_format((float) $row['line']->debit_ils, 2) : '' }}</td><td class="px-4 py-2" dir="ltr">{{ (float) $row['line']->credit_ils ? number_format((float) $row['line']->credit_ils, 2) : '' }}</td><td class="px-4 py-2 font-medium" dir="ltr">{{ number_format((float) $row['balance'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-modal show="showCreate" title="حساب نقدي/بنكي جديد">
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">الاسم</label><input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">النوع</label><select wire:model="type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@foreach ($typeOptions as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm text-slate-600">العملة</label><select wire:model="currency" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="ILS">ILS</option><option value="USD">USD</option></select></div>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">حساب الأستاذ (GL)</label><select wire:model="gl_account_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">—</option>@foreach ($cashGlAccounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach</select>@error('gl_account_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">الرصيد الافتتاحي</label><input type="number" step="0.01" wire:model="opening_balance" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label class="mb-1 block text-sm text-slate-600">تاريخه</label><input type="date" wire:model="opening_balance_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showCreate = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="saveAccount" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>

    <x-modal show="showTransfer" title="تحويل بين الحسابات">
        <p class="mb-3 text-xs text-slate-400">التحويل بين نفس العملة فقط.</p>
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">من حساب</label><select wire:model="from_account_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->currency }})</option>@endforeach</select></div>
            <div><label class="mb-1 block text-sm text-slate-600">إلى حساب</label><select wire:model="to_account_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->currency }})</option>@endforeach</select></div>
            <div><label class="mb-1 block text-sm text-slate-600">المبلغ</label><input type="number" step="0.01" wire:model="transfer_amount" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('transfer_amount')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showTransfer = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="saveTransfer" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">تحويل</button></div>
    </x-modal>

    <x-modal show="showConfirm" title="تأكيد الإجراء">
        <p class="text-sm text-slate-600">
            @if ($confirmAction === 'delete')
                سيتم حذف الحساب <span class="font-semibold">{{ $confirmName }}</span> نهائياً. هذا الإجراء متاح فقط لأنه لا حركات مالية مرتبطة به.
            @elseif ($confirmAction === 'deactivate')
                سيتم تعطيل الحساب <span class="font-semibold">{{ $confirmName }}</span>. لن يظهر في قوائم الإيداع والتحويلات الجديدة، لكن تبقى حركاته وكشوفه وتقاريره كما هي.
            @else
                سيتم تفعيل الحساب <span class="font-semibold">{{ $confirmName }}</span> وإعادته إلى قوائم الحركات المالية.
            @endif
        </p>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="$wire.showConfirm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button>
            <button wire:click="runConfirm" class="rounded-lg px-4 py-2 text-sm font-semibold text-white {{ $confirmAction === 'delete' ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700' }}">تأكيد</button>
        </div>
    </x-modal>
</div>
