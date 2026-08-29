<div>
    <x-page-header :title="'كشف حساب — '.$customer->name" subtitle="العملة الرسمية: الدولار الأمريكي (USD)">
        <x-slot:actions>
            <a href="{{ route('admin.customers.show', $customer) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">ملف العميل</a>
            <button onclick="window.print()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">طباعة</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="إجمالي المستحق (Outstanding)" :value="'$'.number_format((float) $balance['outstanding_usd'], 2)" hint="USD" icon="invoice" tone="amber" />
        <x-stat-card label="رصيد دائن غير مخصص" :value="'$'.number_format((float) $balance['unallocated_credit_usd'], 2)" hint="USD" icon="wallet" tone="emerald" />
        <x-stat-card label="صافي الرصيد (Net)" :value="'$'.number_format((float) $balance['net_balance_usd'], 2)" hint="مستحق − دائن" icon="cash" tone="brand" />
    </div>

    @if ($balance['estimated_outstanding_ils'] !== null)
        <p class="mb-4 text-xs text-slate-400">قيمة تقديرية بالشيكل بسعر اليوم: {{ number_format((float) $balance['estimated_outstanding_ils'], 2) }} ₪ (تقديرية فقط — ليست الرصيد الرسمي).</p>
    @endif

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المرجع</th>
                    <th class="px-4 py-3">البيان</th><th class="px-4 py-3">مدين (فاتورة)</th>
                    <th class="px-4 py-3">دائن (دفعة)</th><th class="px-4 py-3">الرصيد</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($statement['entries'] as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $row['date']->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $row['reference'] }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $row['description'] }}</td>
                        <td class="px-4 py-3 text-slate-800" dir="ltr">{{ (float) $row['debit_usd'] > 0 ? '$'.number_format((float) $row['debit_usd'], 2) : '—' }}</td>
                        <td class="px-4 py-3 text-emerald-700" dir="ltr">{{ (float) $row['credit_usd'] > 0 ? '$'.number_format((float) $row['credit_usd'], 2) : '—' }}</td>
                        <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">${{ number_format((float) $row['balance_usd'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">لا حركات على الحساب.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-slate-50 text-sm font-semibold">
                <tr>
                    <td colspan="5" class="px-4 py-3 text-left text-slate-600">الرصيد الختامي (USD)</td>
                    <td class="px-4 py-3 text-slate-900" dir="ltr">${{ number_format((float) $statement['closing_balance_usd'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
