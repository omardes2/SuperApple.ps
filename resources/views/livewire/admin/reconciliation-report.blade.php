<div>
    <x-page-header title="تقارير المطابقة" subtitle="مطابقة أرصدة الأستاذ العام مع السجلات الفرعية (ILS)" />

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach ($rows as $r)
            <div class="rounded-xl border {{ $r['balanced'] ? 'border-emerald-200' : 'border-red-200' }} bg-white p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-800">{{ $r['label'] }}</h3>
                    <x-badge :class="$r['balanced'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">{{ $r['balanced'] ? 'مطابق' : 'فرق' }}</x-badge>
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">رصيد الأستاذ (GL)</dt><dd dir="ltr">{{ number_format((float) $r['gl_balance'], 2) }} ₪</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">السجل الفرعي</dt><dd dir="ltr">{{ number_format((float) $r['sub_ledger'], 2) }} ₪</dd></div>
                    <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-500">الفرق</dt><dd class="font-bold" dir="ltr">{{ number_format((float) $r['difference'], 2) }} ₪</dd></div>
                </dl>
            </div>
        @endforeach
    </div>
</div>
