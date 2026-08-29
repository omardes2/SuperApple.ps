<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-slate-800">مرحباً، {{ auth()->user()->name }} 👋</h2>
        <p class="text-sm text-slate-500">نظرة عامة على أداء الشركة اليوم.</p>
    </div>

    {{-- KPI cards — each financial card only renders for authorised users --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @can('reports.financial')
            <x-stat-card label="إيرادات الشهر" value="—" hint="USD" icon="cash" tone="emerald" />
            <x-stat-card label="المحصّل هذا الشهر" value="—" hint="USD" icon="wallet" tone="brand" />
            <x-stat-card label="المستحق (ذمم)" value="—" hint="USD" icon="invoice" tone="amber" />
            <x-stat-card label="صافي الربح" value="—" hint="تقديري" icon="chart" tone="violet" />
        @endcan

        @can('projects.view')
            <x-stat-card label="المشاريع النشطة" value="—" icon="folder" tone="brand" />
        @endcan
        @can('tasks.view')
            <x-stat-card label="مهام متأخرة" value="—" icon="check" tone="red" />
        @endcan
        @can('customers.view')
            <x-stat-card label="العملاء النشطون" value="—" icon="users" tone="slate" />
        @endcan
        @can('attendance.view')
            <x-stat-card label="حضور اليوم" value="—" icon="clock" tone="emerald" />
        @endcan
    </div>

    {{-- Operational overview (customers / projects / tasks) --}}
    @if ($ops)
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-500">النشاط التشغيلي</h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                @can('customers.view')
                    <a href="{{ route('admin.customers') }}" class="block"><x-stat-card label="عملاء نشطون" :value="$ops['active_customers']" icon="users" tone="brand" /></a>
                @endcan
                @can('projects.view')
                    <a href="{{ route('admin.projects') }}" class="block"><x-stat-card label="مشاريع نشطة" :value="$ops['active_projects']" icon="folder" tone="emerald" /></a>
                @endcan
                @can('tasks.view')
                    <a href="{{ route('admin.tasks') }}" class="block"><x-stat-card label="مهام اليوم" :value="$ops['tasks_today']" icon="check" tone="violet" /></a>
                    <a href="{{ route('admin.tasks', ['status' => 'waiting_review']) }}" class="block"><x-stat-card label="بانتظار المراجعة" :value="$ops['waiting_review']" icon="doc" tone="amber" /></a>
                    <a href="{{ route('admin.tasks') }}" class="block"><x-stat-card label="مهام متأخرة" :value="$ops['late_tasks']" icon="minus" tone="red" /></a>
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
