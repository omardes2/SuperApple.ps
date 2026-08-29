<div class="space-y-5">
    <x-page-header title="مهامي" subtitle="المهام المسندة إليك" />

    @php
        $filters = [
            'all' => 'الكل', 'today' => 'اليوم', 'late' => 'متأخرة',
            'in_progress' => 'قيد العمل', 'waiting_review' => 'بانتظار المراجعة',
            'changes_requested' => 'مطلوب تعديلات', 'completed' => 'مكتملة',
        ];
    @endphp
    <div class="flex flex-wrap gap-2">
        @foreach ($filters as $key => $label)
            <button wire:click="$set('filter', '{{ $key }}')"
                    class="rounded-full border px-3 py-1.5 text-sm transition {{ $filter === $key ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50' }}">
                {{ $label }}
                <span class="mr-1 rounded-full {{ $filter === $key ? 'bg-white/20' : 'bg-slate-100' }} px-1.5 text-xs">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($tasks as $task)
            <a href="{{ route('employee.tasks.show', $task) }}" class="block rounded-xl border border-slate-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium text-slate-800">{{ $task->title }}</p>
                        <p class="mt-0.5 text-xs text-slate-500" dir="ltr">{{ $task->task_number }}
                            @if ($task->project) · {{ $task->project->name }} @endif
                            @if ($task->customer) · {{ $task->customer->name }} @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-badge :class="$task->priority->badgeClass()">{{ $task->priority->label() }}</x-badge>
                        <x-badge :class="$task->status->badgeClass()">{{ $task->status->label() }}</x-badge>
                    </div>
                </div>
                @if ($task->due_date)
                    <p class="mt-2 text-xs {{ $task->isLate() ? 'text-red-600' : 'text-slate-400' }}" dir="ltr">
                        التسليم: {{ $task->due_date->format('Y-m-d') }} {{ $task->isLate() ? '(متأخرة)' : '' }}
                    </p>
                @endif
            </a>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-400">لا مهام في هذا التصنيف.</div>
        @endforelse
    </div>

    <div>{{ $tasks->links() }}</div>
</div>
