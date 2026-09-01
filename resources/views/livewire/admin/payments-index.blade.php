<div>
    <x-page-header title="الدفعات والتحصيل" subtitle="الرصيد الرسمي للعملاء بالدولار الأمريكي (USD) — دفعات الشيكل تُحوّل بسعر صرف مستقل لكل دفعة">
        <x-slot:actions>
            @can('create', \App\Models\Payment::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ دفعة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>@endif

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat-card label="محصّل هذا الشهر" :value="'$'.number_format((float) $stats['collected_month_usd'], 2)" :hint="'≈ '.number_format((float) $stats['collected_month_ils'], 2).' ₪'" icon="cash" tone="emerald" />
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
                    <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المبلغ (₪)</th>
                    <th class="px-4 py-3">الطريقة</th>
                    <th class="px-4 py-3">الحالة</th>
                    <th class="px-4 py-3 text-center">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($payments as $payment)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">
                            <a href="{{ route('admin.payments.show', $payment) }}" class="hover:text-brand-600 hover:underline">{{ $payment->payment_number }}</a>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $payment->customer?->name ?? '— بلا عميل' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $payment->payment_date->format('Y-m-d') }}</td>
                        @php
                            // Primary ILS = the actual shekels for an ILS payment, or the USD
                            // payment's own accounting valuation (usd_equivalent × its stored
                            // rate) — never a current rate. Secondary = original / equivalent.
                            $payIsIls = $payment->payment_currency->value === 'ILS';
                            $payRate = $payment->exchange_rate;
                            $payIls = $payIsIls
                                ? $payment->payment_amount
                                : ((float) $payRate > 0 ? \App\Support\Money::convertUsdToIls($payment->usd_equivalent, $payRate) : null);
                        @endphp
                        <td class="px-4 py-3 font-semibold text-slate-800">
                            <x-amount :ils="$payIls" :usd="$payIsIls ? $payment->usd_equivalent : $payment->payment_amount" :usd-approx="$payIsIls" />
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $payment->payment_method->label() }}</td>
                        <td class="px-4 py-3"><x-badge :class="$payment->status->badgeClass()">{{ $payment->status->label() }}</x-badge></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-center gap-1.5">
                                {{-- View --}}
                                <a href="{{ route('admin.payments.show', $payment) }}" title="مشاهدة الدفعة"
                                   class="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-600">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S5.5 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                </a>

                                {{-- Edit: only a draft can be edited (posted payments are immutable) --}}
                                @can('update', $payment)
                                    <a href="{{ route('admin.payments.show', $payment) }}" title="تعديل"
                                       class="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    </a>
                                @else
                                    <span title="لا يمكن تعديل دفعة مُرحّلة" class="cursor-not-allowed rounded p-1.5 text-slate-300">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    </span>
                                @endcan

                                {{-- Delete: any status. A posted payment is reversed (invoices restored
                                     + GL mirror-reversed) before the record is removed. --}}
                                @can('delete', $payment)
                                    @php
                                        $delConfirm = $payment->status->isDraft()
                                            ? 'سيتم حذف مسودة الدفعة نهائياً. متابعة؟'
                                            : 'سيتم عكس القيود المحاسبية لهذه الدفعة (واستعادة أرصدة الفواتير) ثم حذفها نهائياً. متابعة؟';
                                    @endphp
                                    <button type="button" title="حذف الدفعة"
                                            wire:click="deletePayment({{ $payment->id }})"
                                            wire:confirm="{{ $delConfirm }}"
                                            class="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0v12a2 2 0 002 2h4a2 2 0 002-2V7"/></svg>
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا دفعات.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
</div>
