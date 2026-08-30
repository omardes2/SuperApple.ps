<div>
    <x-page-header title="أعمار الذمم المدينة" subtitle="المبالغ المستحقة على العملاء بالدولار (USD) موزّعة حسب التأخر">
        <x-slot:actions>
            <input type="date" wire:model.live="asOf" dir="ltr" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @if ($canExport)<button wire:click="export" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">تصدير CSV</button>@endif
            <a href="{{ route('admin.reports') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">مركز التقارير</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-5">
        <x-stat-card label="غير مستحقة" :value="$fmt::usd($data['buckets']['current'])" :hint="'≈ '.$fmt::ils($data['buckets_ils']['current'])" icon="check" tone="emerald" />
        <x-stat-card label="متأخرة 1–30" :value="$fmt::usd($data['buckets']['1_30'])" :hint="'≈ '.$fmt::ils($data['buckets_ils']['1_30'])" icon="clock" tone="amber" />
        <x-stat-card label="متأخرة 31–60" :value="$fmt::usd($data['buckets']['31_60'])" :hint="'≈ '.$fmt::ils($data['buckets_ils']['31_60'])" icon="clock" tone="amber" />
        <x-stat-card label="متأخرة 61–90" :value="$fmt::usd($data['buckets']['61_90'])" :hint="'≈ '.$fmt::ils($data['buckets_ils']['61_90'])" icon="clock" tone="red" />
        <x-stat-card label="متأخرة +90" :value="$fmt::usd($data['buckets']['90_plus'])" :hint="'≈ '.$fmt::ils($data['buckets_ils']['90_plus'])" icon="shield" tone="red" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">العميل</th><th class="px-4 py-3">الفواتير</th><th class="px-4 py-3">المتبقي USD</th><th class="px-4 py-3">أقدم استحقاق</th><th class="px-4 py-3">أيام التأخر</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($data['rows'] as $r)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $r['customer']?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $r['invoices'] }}</td>
                        <td class="px-4 py-3"><x-money :usd="$r['remaining_usd']" :ils="$r['remaining_ils'] ?? null" class="text-slate-700" dir="ltr" /></td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $r['oldest_due'] ?? '—' }}</td>
                        <td class="px-4 py-3" dir="ltr"><span class="{{ $r['max_days_overdue'] > 90 ? 'text-red-600 font-semibold' : ($r['max_days_overdue'] > 0 ? 'text-amber-600' : 'text-slate-500') }}">{{ $r['max_days_overdue'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا ذمم مستحقة.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-slate-50 font-semibold text-slate-700"><tr><td class="px-4 py-3">الإجمالي</td><td></td><td class="px-4 py-3"><x-money :usd="$data['total']" :ils="$data['total_ils'] ?? null" dir="ltr" /></td><td colspan="2"></td></tr></tfoot>
        </table>
    </div>
</div>
