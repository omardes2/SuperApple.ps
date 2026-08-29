<div>
    <x-page-header title="تقرير فروقات الصرف" subtitle="فروقات الصرف المحققة عند التحصيل (بالشيكل ILS) — ليست إيرادات مبيعات">
        <x-slot:actions>
            <button onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-end">
        <div>
            <label class="mb-1 block text-xs text-slate-500">من تاريخ</label>
            <input type="date" wire:model.live="from" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs text-slate-500">إلى تاريخ</label>
            <input type="date" wire:model.live="to" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex-1">
            <label class="mb-1 block text-xs text-slate-500">العميل</label>
            <select wire:model.live="customer" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">كل العملاء</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
        </div>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="إجمالي الأرباح" :value="'+'.number_format((float) $totals['gain_ils'], 2).' ₪'" hint="فروقات موجبة" icon="cash" tone="emerald" />
        <x-stat-card label="إجمالي الخسائر" :value="number_format((float) $totals['loss_ils'], 2).' ₪'" hint="فروقات سالبة" icon="minus" tone="red" />
        <x-stat-card label="الصافي" :value="((float) $totals['net_ils'] >= 0 ? '+' : '').number_format((float) $totals['net_ils'], 2).' ₪'" hint="ربح/خسارة صافية" icon="repeat" :tone="(float) $totals['net_ils'] >= 0 ? 'brand' : 'amber'" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الدفعة</th>
                    <th class="px-4 py-3">العميل</th><th class="px-4 py-3">الفاتورة</th>
                    <th class="px-4 py-3">المخصّص USD</th><th class="px-4 py-3">سعر الفاتورة</th>
                    <th class="px-4 py-3">سعر الدفعة</th><th class="px-4 py-3">فرق الصرف ILS</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $row->payment->payment_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">
                            <a href="{{ route('admin.payments.show', $row->payment) }}" class="hover:text-brand-600 hover:underline">{{ $row->payment->payment_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $row->payment->customer->name }}</td>
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $row->invoice?->invoice_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">${{ number_format((float) $row->allocated_usd, 2) }}</td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $row->invoice_exchange_rate }}</td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $row->payment_exchange_rate }}</td>
                        <td class="px-4 py-3 font-semibold {{ (float) $row->exchange_difference_ils > 0 ? 'text-emerald-700' : ((float) $row->exchange_difference_ils < 0 ? 'text-red-600' : 'text-slate-500') }}" dir="ltr">
                            {{ (float) $row->exchange_difference_ils > 0 ? '+' : '' }}{{ number_format((float) $row->exchange_difference_ils, 2) }} ₪
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا فروقات صرف في هذه الفترة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
