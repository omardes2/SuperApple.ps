<div>
    <x-page-header :title="'كشف حساب — '.$customer->name" subtitle="العملة الرسمية: الدولار الأمريكي (USD)">
        <x-slot:actions>
            <a href="{{ route('admin.customers.show', $customer) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">ملف العميل</a>
            <button onclick="window.print()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">طباعة</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @php $netIls = app(\App\Support\CurrencyDisplay::class)->estimatedIls($balance['net_balance_usd']); @endphp
        <x-stat-card label="إجمالي المستحق (Outstanding)" :value="'$'.number_format((float) $balance['outstanding_usd'], 2)" :hint="isset($balance['outstanding_ils_by_document']) ? '≈ '.number_format((float) $balance['outstanding_ils_by_document'], 2).' ₪' : 'USD'" icon="invoice" tone="amber" />
        <x-stat-card label="رصيد دائن غير مخصص" :value="'$'.number_format((float) $balance['unallocated_credit_usd'], 2)" hint="USD" icon="wallet" tone="emerald" />
        <x-stat-card label="صافي الرصيد (Net)" :value="'$'.number_format((float) $balance['net_balance_usd'], 2)" :hint="$netIls !== null ? '≈ '.number_format((float) $netIls, 2).' ₪ (تقديري)' : 'مستحق − دائن'" icon="cash" tone="brand" />
    </div>

    @if ($balance['estimated_outstanding_ils'] !== null)
        <p class="mb-4 text-xs text-slate-400">قيمة تقديرية بالشيكل بسعر اليوم: {{ number_format((float) $balance['estimated_outstanding_ils'], 2) }} ₪ (تقديرية فقط — ليست الرصيد الرسمي).</p>
    @endif

    @include('livewire.admin.partials.statement-table', ['statement' => $statement])
</div>
