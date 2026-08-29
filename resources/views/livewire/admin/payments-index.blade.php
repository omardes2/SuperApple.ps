<div>
    <x-page-header title="الدفعات والتحصيل" subtitle="الرصيد الرسمي للعملاء بالدولار الأمريكي (USD) — دفعات الشيكل تُحوّل بسعر صرف مستقل لكل دفعة">
        <x-slot:actions>
            @can('create', \App\Models\Payment::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ دفعة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat-card label="محصّل هذا الشهر" :value="'$'.number_format((float) $stats['collected_month_usd'], 2)" hint="USD (ما يعادله)" icon="cash" tone="emerald" />
        <x-stat-card label="شيكل مستلم هذا الشهر" :value="number_format((float) $stats['collected_month_ils_original'], 2).' ₪'" hint="القيمة الأصلية بالشيكل" icon="repeat" tone="brand" />
        <x-stat-card label="أرصدة غير مخصصة" :value="'$'.number_format((float) $stats['unallocated_credit_usd'], 2)" hint="رصيد دائن للعملاء" icon="wallet" tone="amber" />
        <x-stat-card label="دفعات مُرحّلة" :value="$stats['posted_count']" icon="invoice" tone="slate" />
        <x-stat-card label="دفعات ملغاة" :value="$stats['cancelled_count']" icon="minus" tone="red" />
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
                    <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المبلغ المستلم</th>
                    <th class="px-4 py-3">ما يعادله USD</th><th class="px-4 py-3">الطريقة</th>
                    <th class="px-4 py-3">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($payments as $payment)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">
                            <a href="{{ route('admin.payments.show', $payment) }}" class="hover:text-brand-600 hover:underline">{{ $payment->payment_number }}</a>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $payment->customer->name }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $payment->payment_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">
                            {{ number_format((float) $payment->payment_amount, 2) }} {{ $payment->payment_currency->symbol() }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">${{ number_format((float) $payment->usd_equivalent, 2) }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $payment->payment_method->label() }}</td>
                        <td class="px-4 py-3"><x-badge :class="$payment->status->badgeClass()">{{ $payment->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا دفعات.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
</div>
