<div>
    <x-page-header title="تقارير المشاريع والمهام" subtitle="حالة المشاريع، إنتاجية المهام، والتوزيع">
        <x-slot:actions><a href="{{ route('admin.reports') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">مركز التقارير</a></x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-stat-card label="مشاريع نشطة" :value="$activeProjects" icon="folder" tone="brand" />
        <x-stat-card label="مشاريع متأخرة" :value="$lateProjects" icon="clock" tone="red" />
        <x-stat-card label="نسبة إنجاز المهام" :value="$completionRate.'%'" icon="check" tone="emerald" />
        <x-stat-card label="متوسط زمن الإنجاز (يوم)" :value="$avgCompletionDays ?? '—'" icon="clock" tone="slate" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">المشاريع حسب الحالة</h3>
            <table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100">
                @foreach ($statuses as $val => $label)
                    <tr><td class="py-2 text-slate-700">{{ $label }}</td><td class="py-2 text-left font-medium" dir="ltr">{{ $projectsByStatus[$val] ?? 0 }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">المهام حسب الحالة</h3>
            <table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100">
                @foreach ($taskStatuses as $val => $label)
                    <tr><td class="py-2 text-slate-700">{{ $label }}</td><td class="py-2 text-left font-medium" dir="ltr">{{ $tasksByStatus[$val] ?? 0 }}</td></tr>
                @endforeach
                <tr class="bg-red-50"><td class="py-2 text-red-700">مهام متأخرة</td><td class="py-2 text-left font-semibold text-red-700" dir="ltr">{{ $lateTasks }}</td></tr>
            </tbody></table>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">المشاريع النشطة حسب المدير</h3>
            <table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100">
                @forelse ($byManager as $row)
                    <tr><td class="py-2 text-slate-700">{{ $row->projectManager?->full_name ?? 'غير محدد' }}</td><td class="py-2 text-left font-medium" dir="ltr">{{ $row->c }}</td></tr>
                @empty <tr><td class="py-6 text-center text-slate-400">لا بيانات.</td></tr> @endforelse
            </tbody></table>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">المشاريع النشطة حسب القسم</h3>
            <table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100">
                @forelse ($byDepartment as $row)
                    <tr><td class="py-2 text-slate-700">{{ $row->department?->name ?? 'غير محدد' }}</td><td class="py-2 text-left font-medium" dir="ltr">{{ $row->c }}</td></tr>
                @empty <tr><td class="py-6 text-center text-slate-400">لا بيانات.</td></tr> @endforelse
            </tbody></table>
        </div>
    </div>
</div>
