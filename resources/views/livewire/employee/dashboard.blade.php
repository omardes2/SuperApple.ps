<div class="space-y-6">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-lg font-bold text-slate-800">صباح الخير، {{ auth()->user()->name }} ☀️</h2>
        <p class="text-sm text-slate-500">هذه مساحتك التشغيلية — المهام، المشاريع، والدوام.</p>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">تسجيل الحضور</p>
            <button class="mt-2 w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">تسجيل حضور</button>
            <p class="mt-2 text-xs text-slate-400">سيتم تفعيل الدوام في المرحلة القادمة.</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">مهامي اليوم</p>
            <p class="mt-1 text-2xl font-bold text-slate-800">—</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">رصيد الإجازات</p>
            <p class="mt-1 text-2xl font-bold text-slate-800">—</p>
        </div>
    </div>

    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
        <p class="text-sm text-slate-500">لا توجد بيانات مالية في واجهة الموظف — بشكل كامل.</p>
    </div>
</div>
