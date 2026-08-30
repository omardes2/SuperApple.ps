<div>
    <x-page-header title="الاشتراكات" subtitle="عقود متكررة تُولّد فواتير دورية عبر نظام الفوترة القياسي">
        <x-slot:actions>@if ($canCreate)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ اشتراك</button>@endif</x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    @if ($canReports)
        <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-4">
            @php $mrrIls = app(\App\Support\CurrencyDisplay::class)->estimatedIls($summary['mrr_usd']); $arrIls = app(\App\Support\CurrencyDisplay::class)->estimatedIls($summary['arr_usd']); @endphp
            <x-stat-card label="الإيراد المتكرر الشهري (MRR)" :value="'$'.number_format((float) $summary['mrr_usd'], 2)" :hint="$mrrIls !== null ? '≈ '.number_format((float) $mrrIls, 2).' ₪ (قيمة تعاقدية)' : 'قيمة تعاقدية — ليست إيراداً محاسبياً'" icon="repeat" tone="brand" />
            <x-stat-card label="الإيراد المتكرر السنوي (ARR)" :value="'$'.number_format((float) $summary['arr_usd'], 2)" :hint="$arrIls !== null ? '≈ '.number_format((float) $arrIls, 2).' ₪ (MRR × 12)' : 'MRR × 12'" icon="chart" tone="emerald" />
            <x-stat-card label="اشتراكات نشطة" :value="$summary['active']" icon="check" tone="teal" />
            <x-stat-card label="إجمالي الاشتراكات" :value="array_sum($summary['counts'])" icon="grid" tone="slate" />
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-3">
        <input wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم أو الرقم..." class="w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="statusFilter" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الاسم</th><th class="px-4 py-3">العميل</th>
                    <th class="px-4 py-3">الدورة</th>@if ($canPrices)<th class="px-4 py-3">القيمة</th>@endif
                    <th class="px-4 py-3">الفوترة القادمة</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($subscriptions as $sub)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $sub->subscription_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $sub->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $sub->customer?->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $sub->billing_cycle->label() }}{{ $sub->billing_interval > 1 ? ' ×'.$sub->billing_interval : '' }}</td>
                        @if ($canPrices)<td class="px-4 py-3"><x-money :usd="$sub->total_usd" :useLatest="true" class="text-slate-700" dir="ltr" /></td>@endif
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $sub->next_billing_date?->toDateString() ?? '—' }}</td>
                        <td class="px-4 py-3"><x-badge :class="$sub->status->badgeClass()">{{ $sub->status->label() }}</x-badge></td>
                        <td class="px-4 py-3"><a href="{{ route('admin.subscriptions.show', $sub) }}" class="text-brand-600 hover:underline">تفاصيل</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا اشتراكات.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $subscriptions->links() }}</div>

    <x-modal show="showCreate" title="اشتراك جديد" maxWidth="max-w-3xl">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">العميل</label><select wire:model="customer_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">— اختر —</option>@foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>@error('customer_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm text-slate-600">المشروع (اختياري)</label><select wire:model="project_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">—</option>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">اسم الاشتراك</label><input wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-3 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">الدورة</label><select wire:model="billing_cycle" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@foreach ($cycles as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm text-slate-600">كل (فترة)</label><input type="number" min="1" wire:model="billing_interval" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label class="mb-1 block text-sm text-slate-600">مهلة السداد (أيام)</label><input type="number" min="0" wire:model="payment_terms_days" dir="ltr" placeholder="افتراضي" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">تاريخ البدء</label><input type="date" wire:model="start_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('start_date')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm text-slate-600">تاريخ الانتهاء (اختياري)</label><input type="date" wire:model="end_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('end_date')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex gap-6 rounded-lg bg-slate-50 px-3 py-2">
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="auto_generate_invoice"> توليد فاتورة تلقائياً (مسودة)</label>
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="auto_issue_invoice"> إصدار الفاتورة تلقائياً</label>
            </div>

            <div class="rounded-lg border border-slate-200 p-3">
                <div class="mb-2 flex items-center justify-between"><span class="text-sm font-semibold text-slate-700">البنود</span><button type="button" wire:click="addItem" class="rounded border border-brand-300 px-2 py-1 text-xs text-brand-700">+ بند</button></div>
                @error('items')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="space-y-2">
                    @foreach ($items as $i => $item)
                        <div class="grid grid-cols-12 gap-2">
                            <input wire:model="items.{{ $i }}.item_name" placeholder="اسم البند" class="col-span-5 rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="number" step="0.01" wire:model="items.{{ $i }}.quantity" placeholder="كمية" dir="ltr" class="col-span-2 rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="number" step="0.0001" wire:model="items.{{ $i }}.unit_price_usd" placeholder="سعر $" dir="ltr" class="col-span-2 rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="number" step="0.01" wire:model="items.{{ $i }}.tax_rate" placeholder="ض%" dir="ltr" class="col-span-2 rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <button type="button" wire:click="removeItem({{ $i }})" class="col-span-1 rounded border border-red-200 text-xs text-red-600">×</button>
                        </div>
                    @endforeach
                </div>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">الشروط (اختياري)</label><textarea wire:model="terms" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea></div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showCreate=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="save" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>
</div>
