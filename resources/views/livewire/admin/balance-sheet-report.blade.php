<div>
    <x-page-header title="الميزانية العمومية" subtitle="بالشيكل (ILS)">
        <x-slot:actions>
            <input type="date" wire:model.live="asOf" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <button onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-brand-700">الأصول</h3>
            <table class="min-w-full text-sm">
                @foreach ($report['assets'] as $r)
                    <tr class="border-b border-slate-100"><td class="py-2 text-slate-700">{{ $r['account']->name }}</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $r['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="font-bold"><td class="py-2">إجمالي الأصول</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $report['total_assets'], 2) }}</td></tr>
            </table>
        </div>
        <div class="space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-amber-700">الالتزامات</h3>
                <table class="min-w-full text-sm">
                    @forelse ($report['liabilities'] as $r)
                        <tr class="border-b border-slate-100"><td class="py-2 text-slate-700">{{ $r['account']->name }}</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $r['amount'], 2) }}</td></tr>
                    @empty<tr><td class="py-2 text-slate-400">—</td></tr>@endforelse
                    <tr class="font-semibold"><td class="py-2">إجمالي الالتزامات</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $report['total_liabilities'], 2) }}</td></tr>
                </table>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-violet-700">حقوق الملكية</h3>
                <table class="min-w-full text-sm">
                    @foreach ($report['equity'] as $r)
                        <tr class="border-b border-slate-100"><td class="py-2 text-slate-700">{{ $r['account']->name ?? $r['label'] }}</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $r['amount'], 2) }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td class="py-2">إجمالي حقوق الملكية</td><td class="py-2 text-left" dir="ltr">{{ number_format((float) $report['total_equity'], 2) }}</td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-5 rounded-lg p-4 text-center {{ $report['balanced'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
        {{ $report['balanced'] ? '✓' : '✗' }} الأصول ({{ number_format((float) $report['total_assets'], 2) }}) = الالتزامات + حقوق الملكية ({{ number_format((float) $report['total_liabilities_equity'], 2) }})
    </div>
</div>
