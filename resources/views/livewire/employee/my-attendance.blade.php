<div class="space-y-6">
    <x-page-header title="دوامي" subtitle="سجّل حضورك وانصرافك وتابع ساعات عملك" />

    @error('attendance') <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-1">
            <p class="text-sm text-slate-500">حالة اليوم</p>
            @if ($today && $today->check_in_at)
                <div class="mt-2 flex items-center gap-2">
                    <x-badge :class="$today->status->badgeClass()">{{ $today->status->label() }}</x-badge>
                </div>
                <div class="mt-3 space-y-1 text-sm text-slate-600">
                    <div class="flex justify-between"><span>الحضور</span><span dir="ltr">{{ $today->check_in_at->format('H:i') }}</span></div>
                    <div class="flex justify-between"><span>الانصراف</span><span dir="ltr">{{ $today->check_out_at?->format('H:i') ?? '—' }}</span></div>
                    <div class="flex justify-between"><span>ساعات العمل</span><span dir="ltr">{{ $today->workedHoursLabel() }}</span></div>
                    <div class="flex justify-between"><span>التأخير</span><span dir="ltr">{{ $today->late_minutes }} دقيقة</span></div>
                </div>
            @else
                <p class="mt-2 text-sm text-slate-400">لم تسجّل حضورك بعد اليوم.</p>
            @endif

            <div class="mt-4">
                @if (! $today || ! $today->check_in_at)
                    @can('attendance.check_in')
                        <button wire:click="checkIn" class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700" wire:loading.attr="disabled">تسجيل حضور</button>
                    @endcan
                @elseif (! $today->check_out_at)
                    @can('attendance.check_out')
                        <button wire:click="checkOut" class="w-full rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-900" wire:loading.attr="disabled">تسجيل انصراف</button>
                    @endcan
                @else
                    <p class="rounded-lg bg-emerald-50 py-2 text-center text-sm text-emerald-700">اكتمل دوام اليوم ✓</p>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:col-span-2 lg:grid-cols-4">
            <x-stat-card label="أيام الحضور" :value="$summary['present'] + $summary['late']" icon="clock" tone="emerald" />
            <x-stat-card label="مرات التأخير" :value="$summary['late']" icon="clock" tone="amber" />
            <x-stat-card label="أيام الإجازة" :value="$summary['leave']" icon="calendar" tone="brand" />
            <x-stat-card label="ساعات إضافية" :value="intdiv($summary['overtime_minutes'], 60).'س'" icon="chart" tone="violet" />
        </div>
    </div>

    <div>
        <h3 class="mb-3 font-semibold text-slate-800">سجل شهر {{ $month }}/{{ $year }}</h3>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الحضور</th><th class="px-4 py-3">الانصراف</th><th class="px-4 py-3">ساعات</th><th class="px-4 py-3">تأخير</th><th class="px-4 py-3">الحالة</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($records as $rec)
                        <tr>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->attendance_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->check_in_at?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->check_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->workedHoursLabel() }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $rec->late_minutes }} د</td>
                            <td class="px-4 py-3"><x-badge :class="$rec->status->badgeClass()">{{ $rec->status->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">لا سجلات لهذا الشهر.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
