<div>
    <x-page-header title="قائمة الدخل (الأرباح والخسائر)" subtitle="بالشيكل (ILS) — فروقات الصرف تظهر كبنود مستقلة">
        <x-slot:actions><button onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</button></x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div><label class="mb-1 block text-xs text-slate-500">من</label><input type="date" wire:model.live="from" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        <div><label class="mb-1 block text-xs text-slate-500">إلى</label><input type="date" wire:model.live="to" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-emerald-700">الإيرادات</h3>
            <table class="min-w-full text-sm">
                @forelse ($report['revenue'] as $r)
                    <tr class="border-b border-slate-100"><td class="py-2 text-slate-700">{{ $r['account']->name }}</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $r['amount'], 2) }}</td></tr>
                @empty
                    <tr><td class="py-2 text-slate-400">لا إيرادات.</td></tr>
                @endforelse
                <tr class="font-bold"><td class="py-2">إجمالي الإيرادات</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $report['total_revenue'], 2) }}</td></tr>
            </table>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-red-700">المصاريف</h3>
            <table class="min-w-full text-sm">
                @forelse ($report['expenses'] as $r)
                    <tr class="border-b border-slate-100"><td class="py-2 text-slate-700">{{ $r['account']->name }}</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $r['amount'], 2) }}</td></tr>
                @empty
                    <tr><td class="py-2 text-slate-400">لا مصاريف.</td></tr>
                @endforelse
                <tr class="font-bold"><td class="py-2">إجمالي المصاريف</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $report['total_expense'], 2) }}</td></tr>
            </table>
        </div>
    </div>

    @php $net = (float) $report['net_profit']; @endphp
    <div class="mt-5 rounded-xl border-2 {{ $net >= 0 ? 'border-emerald-300 bg-emerald-50' : 'border-red-300 bg-red-50' }} p-5 text-center">
        <p class="text-sm text-slate-600">صافي {{ $net >= 0 ? 'الربح' : 'الخسارة' }}</p>
        <p class="text-3xl font-bold {{ $net >= 0 ? 'text-emerald-700' : 'text-red-700' }}" dir="ltr">{{ number_format($net, 2) }} ₪</p>
    </div>
</div>
