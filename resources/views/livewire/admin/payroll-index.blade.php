<div>
    <x-page-header title="الرواتب" subtitle="مسيرات الرواتب الشهرية — بالشيكل (ILS)">
        <x-slot:actions>
            @if ($canCreate)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مسير جديد</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="إجمالي رواتب الشهر" :value="number_format((float) $stats['gross'], 2).' ₪'" icon="wallet" tone="brand" />
        <x-stat-card label="إجمالي الاستقطاعات" :value="number_format((float) $stats['deductions'], 2).' ₪'" icon="minus" tone="amber" />
        <x-stat-card label="صافي الرواتب" :value="number_format((float) $stats['net'], 2).' ₪'" icon="cash" tone="emerald" />
        <x-stat-card label="حالة الشهر الحالي" :value="$stats['status']" icon="doc" tone="slate" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">المسير</th><th class="px-4 py-3">الفترة</th><th class="px-4 py-3">الموظفون</th><th class="px-4 py-3">الإجمالي</th><th class="px-4 py-3">الاستقطاعات</th><th class="px-4 py-3">الصافي</th><th class="px-4 py-3">الحالة</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($runs as $run)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.payroll.show', $run) }}" class="hover:text-brand-600 hover:underline">{{ $run->payroll_number }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $run->periodLabel() }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $run->items_count }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $run->total_gross_ils, 2) }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $run->total_deductions_ils, 2) }}</td>
                        <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">{{ number_format((float) $run->total_net_ils, 2) }}</td>
                        <td class="px-4 py-3"><x-badge :class="$run->status->badgeClass()">{{ $run->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا مسيرات رواتب.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $runs->links() }}</div>

    <x-modal show="showCreate" title="مسير رواتب جديد">
        <div class="grid grid-cols-2 gap-3">
            <div><label class="mb-1 block text-sm text-slate-600">السنة</label><input type="number" wire:model="year" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('year')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="mb-1 block text-sm text-slate-600">الشهر</label><input type="number" min="1" max="12" wire:model="month" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('month')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" @click="$wire.showCreate=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">تراجع</button><button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">إنشاء</button></div>
    </x-modal>
</div>
