<div>
    <x-page-header title="تقارير الاشتراكات" subtitle="MRR/ARR (قيمة تعاقدية — ليست إيراداً محاسبياً) والفوترة القادمة">
        <x-slot:actions><a href="{{ route('admin.reports') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">مركز التقارير</a></x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-stat-card label="اشتراكات نشطة" :value="$a['active']" icon="check" tone="brand" />
        <x-stat-card label="MRR" :value="$fmt::usd($a['mrr_usd'])" hint="شهري تعاقدي" icon="repeat" tone="emerald" />
        <x-stat-card label="ARR" :value="$fmt::usd($a['arr_usd'])" hint="MRR × 12" icon="chart" tone="violet" />
        <x-stat-card label="موقوفة" :value="$a['paused']" icon="clock" tone="amber" />
        <x-stat-card label="تنتهي قريباً" :value="$a['expiring_soon']" icon="clock" tone="amber" />
        <x-stat-card label="فوترة قادمة (30ي)" :value="$a['upcoming']" icon="calendar" tone="slate" />
        <x-stat-card label="فواتير هذا الشهر" :value="$a['invoices_this_month']" icon="invoice" tone="brand" />
        <x-stat-card label="فشل الإصدار التلقائي" :value="$a['auto_issue_failures']" icon="shield" :tone="$a['auto_issue_failures'] > 0 ? 'red' : 'slate'" />
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="mb-3 font-semibold text-slate-800">الفوترة القادمة (خلال 30 يوماً)</h3>
        <table class="min-w-full text-sm">
            <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الاشتراك</th><th class="py-2">العميل</th><th class="py-2">الفوترة القادمة</th><th class="py-2">القيمة</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($upcoming as $s)
                    <tr>
                        <td class="py-2"><a href="{{ route('admin.subscriptions.show', $s) }}" class="text-brand-600 hover:underline">{{ $s->name }}</a></td>
                        <td class="py-2 text-slate-600">{{ $s->customer?->name }}</td>
                        <td class="py-2 text-slate-500" dir="ltr">{{ $s->next_billing_date?->toDateString() }}</td>
                        <td class="py-2 text-slate-700" dir="ltr">{{ $fmt::usd($s->total_usd) }}</td>
                    </tr>
                @empty <tr><td colspan="4" class="py-6 text-center text-slate-400">لا فوترة قادمة.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
</div>
