<div>
    <x-page-header title="الفواتير" subtitle="الرصيد الرسمي بالدولار الأمريكي (USD)">
        <x-slot:actions>
            @can('create', \App\Models\Invoice::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ فاتورة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat-card label="مسودات" :value="$stats['draft']" icon="doc" tone="slate" />
        <x-stat-card label="صادرة هذا الشهر" :value="$stats['issued_month']" icon="invoice" tone="brand" />
        <x-stat-card label="مفوتر هذا الشهر" :value="'$'.number_format((float) $stats['invoiced_month'], 2)" :hint="'≈ '.number_format((float) $stats['invoiced_month_ils'], 2).' ₪'" icon="cash" tone="emerald" />
        <x-stat-card label="المستحق (Outstanding)" :value="'$'.number_format((float) $stats['outstanding'], 2)" :hint="'≈ '.number_format((float) $stats['outstanding_ils'], 2).' ₪'" icon="wallet" tone="amber" />
        <x-stat-card label="متأخرة" :value="$stats['overdue']" icon="minus" tone="red" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالرقم/العميل..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل العملاء</option>
            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
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
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">العميل</th>
                    <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الاستحقاق</th>
                    <th class="px-4 py-3">الإجمالي</th><th class="px-4 py-3">المبلغ المتبقي</th>
                    <th class="px-4 py-3">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invoices as $invoice)
                    @php $eff = $invoice->effectiveStatus(); @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $invoice->invoice_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route('admin.invoices.show', $invoice) }}" class="hover:text-brand-600 hover:underline">{{ $invoice->customer->name }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $invoice->invoice_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3"><x-money :usd="$invoice->total_usd" :ils="$invoice->total_ils_at_issue" :rate="$invoice->exchange_rate" class="font-semibold text-slate-800" dir="ltr" /></td>
                        <td class="px-4 py-3"><x-money :usd="$invoice->remaining_usd" :rate="$invoice->exchange_rate" :class="(float) $invoice->remaining_usd > 0 ? 'font-semibold text-amber-700' : 'font-semibold text-emerald-700'" dir="ltr" /></td>
                        <td class="px-4 py-3"><x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا فواتير.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
</div>
