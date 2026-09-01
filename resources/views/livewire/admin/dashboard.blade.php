<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-slate-800">مرحباً، {{ auth()->user()->name }} 👋</h2>
        <p class="text-sm text-slate-500">نظرة عامة على أداء الشركة اليوم.</p>
    </div>

    {{-- Executive alerts --}}
    @if (! empty($alerts))
        <div class="space-y-2">
            @foreach ($alerts as $alert)
                @php $c = $alert['level'] === 'red' ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700'; @endphp
                <div class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm {{ $c }}">
                    <span>⚠ {{ $alert['text'] }}</span>
                    @isset($alert['route'])<a href="{{ route($alert['route']) }}" class="shrink-0 text-xs underline">عرض</a>@endisset
                </div>
            @endforeach
        </div>
    @endif

    {{-- Financial KPIs — GL revenue (ILS) + official USD figures; finance users only --}}
    @if ($finance)
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">النظرة المالية</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @isset($finance['revenue_month_ils'])
                    <a href="{{ route('admin.reports.profit-loss') }}" class="block"><x-stat-card label="إيراد الشهر (محاسبي)" :value="number_format((float) $finance['revenue_month_ils'], 2).' ₪'" hint="من دفتر الأستاذ" icon="chart" tone="emerald" /></a>
                @endisset
                <a href="{{ route('admin.payments') }}" class="block"><x-stat-card label="التحصيل هذا الشهر" :value="number_format((float) $finance['collected_month_ils'], 2).' ₪'" :hint="'≈ $'.number_format((float) $finance['collected_month_usd'], 2)" icon="cash" tone="emerald" /></a>
                <a href="{{ route('admin.invoices') }}" class="block"><x-stat-card label="المستحق (ذمم)" :value="number_format((float) $finance['outstanding_ils'], 2).' ₪'" :hint="'القيمة الرسمية $'.number_format((float) $finance['outstanding_usd'], 2)" icon="invoice" tone="amber" /></a>
                <a href="{{ route('admin.reports.exchange-gain-loss') }}" class="block"><x-stat-card label="صافي فروقات الصرف (الشهر)" :value="((float) $finance['exchange_net_ils'] >= 0 ? '+' : '−').number_format(abs((float) $finance['exchange_net_ils']), 2).' ₪'" hint="ILS — محقق" icon="repeat" :tone="(float) $finance['exchange_net_ils'] >= 0 ? 'violet' : 'red'" /></a>
            </div>
        </div>
    @endif

    {{-- Charts: revenue vs expenses + cash collection (from GL) --}}
    @if ($charts)
        <div>
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-500">الاتجاهات المالية (₪ من دفتر الأستاذ)</h3>
                <div class="flex gap-1 text-xs">
                    <button wire:click="setChartMonths(6)" class="rounded px-2 py-1 {{ $chartMonths === 6 ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600' }}">6 أشهر</button>
                    <button wire:click="setChartMonths(12)" class="rounded px-2 py-1 {{ $chartMonths === 12 ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600' }}">12 شهراً</button>
                </div>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold text-slate-700">الإيرادات مقابل المصاريف</h4>
                    @include('livewire.admin.partials.dual-bar-chart', [
                        'rows' => collect($charts['revenue_expense'])->map(fn($r) => ['label' => $r['label'], 'a' => $r['revenue'], 'b' => $r['expense']])->all(),
                        'aName' => 'الإيراد', 'bName' => 'المصاريف', 'aClass' => 'bg-emerald-500', 'bClass' => 'bg-red-400',
                    ])
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold text-slate-700">التحصيل الشهري (₪)</h4>
                    @include('livewire.admin.partials.dual-bar-chart', [
                        'rows' => collect($charts['cash'])->map(fn($r) => ['label' => $r['label'], 'a' => $r['collected_ils'], 'b' => 0])->all(),
                        'aName' => 'محصّل (₪)', 'bName' => '', 'aClass' => 'bg-brand-500', 'bClass' => 'bg-transparent',
                    ])
                </div>
            </div>
        </div>
    @endif

    {{-- AR aging summary + top customers --}}
    @if ($aging)
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-slate-700">أعمار الذمم المدينة (₪)</h4>
                    <a href="{{ route('admin.reports.ar-aging') }}" class="text-xs text-brand-600 hover:underline">التفاصيل</a>
                </div>
                <div class="grid grid-cols-5 gap-2 text-center text-xs">
                    @foreach (['current'=>'غير مستحقة','1_30'=>'1–30','31_60'=>'31–60','61_90'=>'61–90','90_plus'=>'+90'] as $k=>$lbl)
                        <div class="rounded-lg bg-slate-50 p-2"><div class="text-slate-500">{{ $lbl }}</div>
                            @if (isset($aging['buckets_ils']))
                                <div class="mt-1 font-semibold text-slate-800" dir="ltr">{{ number_format((float) $aging['buckets_ils'][$k], 0) }} ₪</div>
                                <div class="text-[10px] text-slate-400" dir="ltr">≈ ${{ number_format((float) $aging['buckets'][$k], 0) }}</div>
                            @else
                                <div class="mt-1 font-semibold text-slate-800" dir="ltr">${{ number_format((float) $aging['buckets'][$k], 0) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            @if ($topCustomers)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between"><h4 class="text-sm font-semibold text-slate-700">أعلى العملاء مستحقات (₪)</h4><a href="{{ route('admin.reports.customers') }}" class="text-xs text-brand-600 hover:underline">المزيد</a></div>
                    <table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100">
                        @forelse ($topCustomers['outstanding'] as $r)
                            <tr><td class="py-1.5 text-slate-700">{{ $r['customer']?->name }}</td><td class="py-1.5 text-left"><x-amount :ils="$r['amount_ils'] ?? null" :usd="$r['amount']" :usd-approx="false" class="font-medium text-slate-800" /></td></tr>
                        @empty <tr><td class="py-4 text-center text-slate-400">لا مستحقات.</td></tr> @endforelse
                    </tbody></table>
                </div>
            @endif
        </div>
    @endif

    {{-- Accounting KPIs — ILS base currency; accounting users only --}}
    @if ($accounting)
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">المحاسبة (ILS)</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <a href="{{ route('admin.cash-banks') }}" class="block"><x-stat-card label="النقد والبنوك" :value="number_format((float) $accounting['cash_ils'], 2).' ₪'" icon="bank" tone="emerald" /></a>
                <a href="{{ route('admin.suppliers') }}" class="block"><x-stat-card label="ذمم دائنة (موردون)" :value="number_format((float) $accounting['payable_ils'], 2).' ₪'" icon="truck" tone="amber" /></a>
                <a href="{{ route('admin.expenses') }}" class="block"><x-stat-card label="مصاريف الشهر" :value="number_format((float) $accounting['expenses_month_ils'], 2).' ₪'" icon="minus" tone="red" /></a>
                @php $np = (float) $accounting['net_profit_month_ils']; @endphp
                <a href="{{ route('admin.reports.profit-loss') }}" class="block"><x-stat-card label="صافي ربح الشهر" :value="number_format($np, 2).' ₪'" hint="تقديري" icon="chart" :tone="$np >= 0 ? 'violet' : 'red'" /></a>
            </div>
        </div>
    @endif

    {{-- Operational overview (customers / tasks) --}}
    @if ($ops)
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">النشاط التشغيلي</h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                @can('customers.view')
                    <a href="{{ route('admin.customers') }}" class="block"><x-stat-card label="عملاء نشطون" :value="$ops['active_customers']" icon="users" tone="brand" /></a>
                @endcan
                @can('tasks.view')
                    <a href="{{ route('admin.tasks') }}" class="block"><x-stat-card label="مهام اليوم" :value="$ops['tasks_today']" icon="check" tone="violet" /></a>
                    <a href="{{ route('admin.tasks', ['status' => 'waiting_review']) }}" class="block"><x-stat-card label="بانتظار المراجعة" :value="$ops['waiting_review']" icon="doc" tone="amber" /></a>
                    <a href="{{ route('admin.tasks') }}" class="block"><x-stat-card label="مهام متأخرة" :value="$ops['late_tasks']" icon="minus" tone="red" /></a>
                    <a href="{{ route('admin.tasks') }}" class="block"><x-stat-card label="مكتملة هذا الشهر" :value="$ops['completed_month']" icon="check" tone="emerald" /></a>
                @endcan
            </div>
        </div>
    @endif

    {{-- HR operational overview --}}
    @if ($hr)
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">الموارد البشرية اليوم</h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                @can('employees.view')
                    <x-stat-card label="إجمالي الموظفين" :value="$hr['total_employees']" icon="badge" tone="brand" />
                @endcan
                @can('attendance.view')
                    <x-stat-card label="الحاضرون اليوم" :value="$hr['present']" icon="clock" tone="emerald" />
                    <x-stat-card label="المتأخرون اليوم" :value="$hr['late']" icon="clock" tone="amber" />
                    <x-stat-card label="الغائبون اليوم" :value="$hr['absent']" icon="minus" tone="red" />
                @endcan
                @can('leaves.view')
                    <x-stat-card label="في إجازة" :value="$hr['on_leave']" icon="calendar" tone="violet" />
                    <x-stat-card label="طلبات إجازة معلّقة" :value="$hr['pending_leaves']" icon="doc" tone="slate" />
                @endcan
            </div>
        </div>
    @endif
</div>
