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

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-icon name="grid" /></span>
            <div>
                <h3 class="font-semibold text-slate-800">المرحلة الأولى (Sprint 0) جاهزة</h3>
                <p class="text-sm text-slate-500">تم تجهيز البنية الأساسية: المصادقة، الأدوار والصلاحيات، الإعدادات، وسجل العمليات. الوحدات التشغيلية والمالية تُبنى في المراحل التالية.</p>
            </div>
        </div>
    </div>
</div>
