<div>
    <x-page-header title="تقارير المراسلات" subtitle="أداء رسائل واتساب وتذكيرات الدفع">
        <x-slot:actions><a href="{{ route('admin.reports') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">مركز التقارير</a></x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-5">
        <x-stat-card label="أُرسلت" :value="$a['sent']" icon="check" tone="brand" />
        <x-stat-card label="وصلت" :value="$a['delivered']" icon="check" tone="teal" />
        <x-stat-card label="قُرئت" :value="$a['read']" icon="check" tone="emerald" />
        <x-stat-card label="فشلت" :value="$a['failed']" icon="shield" :tone="$a['failed'] > 0 ? 'red' : 'slate'" />
        <x-stat-card label="تذكيرات مُرسلة" :value="$a['reminders_sent']" icon="cash" tone="violet" />
    </div>

    <p class="mb-4 text-xs text-slate-400">ملاحظة: لا يتم ربط الدفعات بالتذكيرات كـ«تحويل» (payment conversion) لعدم وجود علاقة سببية مؤكدة.</p>

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="mb-3 font-semibold text-slate-800">أحدث الرسائل الفاشلة</h3>
        <table class="min-w-full text-sm">
            <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">التاريخ</th><th class="py-2">العميل</th><th class="py-2">الرقم</th><th class="py-2">السبب</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($recentFailed as $m)
                    <tr>
                        <td class="py-2 text-slate-400" dir="ltr">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="py-2 text-slate-600">{{ $m->customer?->name ?? '—' }}</td>
                        <td class="py-2 font-mono text-slate-500" dir="ltr">{{ $m->phone }}</td>
                        <td class="py-2 text-red-600">{{ \Illuminate\Support\Str::limit($m->failure_reason, 50) }}</td>
                    </tr>
                @empty <tr><td colspan="4" class="py-6 text-center text-slate-400">لا رسائل فاشلة.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
</div>
