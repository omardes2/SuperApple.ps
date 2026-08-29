<div class="space-y-5">
    <x-page-header title="مشاريعي" subtitle="المشاريع التي تشارك فيها" />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($projects as $project)
            <a href="{{ route('employee.projects.show', $project) }}" class="block rounded-xl border border-slate-200 bg-white p-5 transition hover:border-brand-300 hover:shadow-sm">
                <div class="mb-2 flex items-start justify-between">
                    <span class="font-mono text-xs text-slate-400" dir="ltr">{{ $project->project_number }}</span>
                    <x-badge :class="$project->status->badgeClass()">{{ $project->status->label() }}</x-badge>
                </div>
                <h3 class="font-semibold text-slate-800">{{ $project->name }}</h3>
                <p class="mt-0.5 text-sm text-slate-500">{{ $project->customer->name }}</p>
                <div class="mt-3 flex items-center gap-2">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" style="width: {{ $project->progress() }}%"></div></div>
                    <span class="text-xs text-slate-500">{{ $project->progress() }}%</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">{{ $project->tasks_count }} مهمة</p>
            </a>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-400">لا مشاريع مسندة إليك.</div>
        @endforelse
    </div>

    <div>{{ $projects->links() }}</div>
</div>
