<div class="space-y-6">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-lg font-bold text-slate-800">صباح الخير، {{ auth()->user()->name }} ☀️</h2>
        <p class="text-sm text-slate-500">{{ now()->translatedFormat('l، j F Y') }}</p>
    </div>

    @error('attendance') <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    @unless ($employee)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
            لا يوجد ملف موظف مرتبط بحسابك بعد. تواصل مع إدارة الموارد البشرية.
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Attendance card --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-500">حالة الحضور اليوم</p>
                @if ($today && $today->check_in_at)
                    <div class="mt-2"><x-badge :class="$today->status->badgeClass()">{{ $today->status->label() }}</x-badge></div>
                    <p class="mt-2 text-sm text-slate-600" dir="ltr">الحضور: {{ $today->check_in_at->format('H:i') }} · الانصراف: {{ $today->check_out_at?->format('H:i') ?? '—' }}</p>
                @else
                    <p class="mt-2 text-sm text-slate-400">لم تسجّل حضورك بعد.</p>
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

            <a href="{{ route('employee.tasks', ['filter' => 'today']) }}" class="block"><x-stat-card label="مهامي اليوم" :value="$tasks['today']" icon="check" tone="brand" /></a>
            <a href="{{ route('employee.tasks', ['filter' => 'late']) }}" class="block"><x-stat-card label="مهام متأخرة" :value="$tasks['late']" icon="minus" tone="red" /></a>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <a href="{{ route('employee.tasks', ['filter' => 'waiting_review']) }}" class="block"><x-stat-card label="بانتظار المراجعة" :value="$tasks['waiting_review']" icon="doc" tone="amber" /></a>
            <a href="{{ route('employee.tasks', ['filter' => 'changes_requested']) }}" class="block"><x-stat-card label="مطلوب تعديلات" :value="$tasks['changes_requested']" icon="check" tone="red" /></a>
            <a href="{{ route('employee.projects') }}" class="block"><x-stat-card label="مشاريعي النشطة" :value="$tasks['projects']" icon="folder" tone="brand" /></a>
            <x-stat-card label="طلبات إجازة معلّقة" :value="$pendingLeaves" icon="calendar" tone="violet" />
        </div>

        @if ($summary)
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-stat-card label="أيام حضور هذا الشهر" :value="$summary['present'] + $summary['late']" icon="clock" tone="emerald" />
                <x-stat-card label="مرات التأخير" :value="$summary['late']" icon="clock" tone="amber" />
                <x-stat-card label="ساعات العمل" :value="intdiv($summary['worked_minutes'], 60).'س'" icon="chart" tone="brand" />
                <x-stat-card label="أيام الإجازة" :value="$summary['leave']" icon="calendar" tone="violet" />
            </div>
        @endif
    @endunless

    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-center text-sm text-slate-400">
        لا توجد أي بيانات مالية في واجهة الموظف.
    </div>
</div>
