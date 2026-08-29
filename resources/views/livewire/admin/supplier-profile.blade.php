<div>
    <div class="mb-5 flex items-center gap-4">
        <a href="{{ route('admin.suppliers') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></a>
        <div>
            <h2 class="text-xl font-bold text-slate-800">{{ $supplier->name }}</h2>
            <p class="text-sm text-slate-500" dir="ltr">{{ $supplier->supplier_number }} · {{ $supplier->phone }}</p>
        </div>
        <div class="mr-auto flex gap-2">
            @can('supplier_bills.create')<button wire:click="newBill" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ فاتورة</button>@endcan
            @can('supplier_payments.create')<button wire:click="newPayment" class="rounded-lg border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50">+ دفعة</button>@endcan
        </div>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="إجمالي المفوتر" :value="number_format((float) $balance['total_billed_ils'], 2).' ₪'" icon="invoice" tone="slate" />
        <x-stat-card label="إجمالي المدفوع" :value="number_format((float) $balance['total_paid_ils'], 2).' ₪'" icon="cash" tone="emerald" />
        <x-stat-card label="المستحق عليه" :value="number_format((float) $balance['outstanding_ils'], 2).' ₪'" icon="minus" tone="amber" />
    </div>

    @php $tabs = ['overview' => 'نظرة عامة', 'bills' => 'الفواتير', 'payments' => 'الدفعات', 'expenses' => 'المصاريف', 'statement' => 'كشف الحساب', 'activity' => 'النشاط']; @endphp
    <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="setTab('{{ $key }}')" class="shrink-0 border-b-2 px-4 py-2.5 text-sm {{ $tab === $key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-500' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <dl class="grid grid-cols-2 gap-3">
                <div><dt class="text-slate-500">الشخص المسؤول</dt><dd>{{ $supplier->contact_person ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">النوع</dt><dd>{{ $supplier->supplier_type ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">الرقم الضريبي</dt><dd dir="ltr">{{ $supplier->tax_number ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">العنوان</dt><dd>{{ $supplier->address ?? '—' }}</dd></div>
            </dl>
        </div>
    @endif

    @if ($tab === 'bills')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الإجمالي</th><th class="px-4 py-3">المتبقي</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($bills as $b)
                        <tr><td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.supplier-bills.show', $b) }}" class="hover:text-brand-600 hover:underline">{{ $b->bill_number }}</a></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $b->bill_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $b->total, 2) }} {{ $b->currency }}</td>
                            <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $b->remaining_original, 2) }} {{ $b->currency }}</td>
                            <td class="px-4 py-3"><x-badge :class="$b->status->badgeClass()">{{ $b->status->label() }}</x-badge></td></tr>
                    @empty<tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا فواتير.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'payments')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">ILS</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $p)
                        <tr><td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.supplier-payments.show', $p) }}" class="hover:text-brand-600 hover:underline">{{ $p->payment_number }}</a></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $p->payment_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $p->amount, 2) }} {{ $p->currency }}</td>
                            <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $p->amount_ils, 2) }} ₪</td>
                            <td class="px-4 py-3"><x-badge :class="$p->status->badgeClass()">{{ $p->status->label() }}</x-badge></td></tr>
                    @empty<tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا دفعات.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'expenses')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الوصف</th><th class="px-4 py-3">ILS</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($expenses as $e)
                        <tr><td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.expenses.show', $e) }}" class="hover:text-brand-600 hover:underline">{{ $e->expense_number }}</a></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $e->expense_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ \Illuminate\Support\Str::limit($e->description, 40) }}</td>
                            <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $e->amount_ils, 2) }} ₪</td></tr>
                    @empty<tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا مصاريف.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'statement')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المرجع</th><th class="px-4 py-3">البيان</th><th class="px-4 py-3">مدين</th><th class="px-4 py-3">دائن</th><th class="px-4 py-3">الرصيد</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($statement['entries'] as $row)
                        <tr><td class="px-4 py-2 text-slate-600" dir="ltr">{{ $row['date']->format('Y-m-d') }}</td><td class="px-4 py-2 font-mono text-slate-500" dir="ltr">{{ $row['ref'] }}</td><td class="px-4 py-2 text-slate-700">{{ $row['desc'] }}</td><td class="px-4 py-2" dir="ltr">{{ (float) $row['debit'] ? number_format((float) $row['debit'], 2) : '—' }}</td><td class="px-4 py-2 text-emerald-700" dir="ltr">{{ (float) $row['credit'] ? number_format((float) $row['credit'], 2) : '—' }}</td><td class="px-4 py-2 font-medium" dir="ltr">{{ number_format((float) $row['balance'], 2) }}</td></tr>
                    @empty<tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">لا حركات.</td></tr>@endforelse
                </tbody>
                <tfoot class="bg-slate-50 font-bold"><tr><td colspan="5" class="px-4 py-3 text-left">الرصيد الختامي (ILS)</td><td class="px-4 py-3" dir="ltr">{{ number_format((float) $statement['closing'], 2) }}</td></tr></tfoot>
            </table>
        </div>
    @endif

    @if ($tab === 'activity')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">العملية</th><th class="px-4 py-3">بواسطة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($activity as $log)
                        <tr><td class="px-4 py-2 text-slate-500" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</td><td class="px-4 py-2"><x-badge>{{ $log->action }}</x-badge></td><td class="px-4 py-2 text-slate-600">{{ $log->user?->name ?? 'النظام' }}</td></tr>
                    @empty<tr><td colspan="3" class="px-4 py-8 text-center text-slate-400">لا نشاط.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
