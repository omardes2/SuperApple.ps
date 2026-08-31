<div>
    <x-page-header title="المهام" subtitle="إدارة مهام الفريق">
        <x-slot:actions>
            @can('tasks.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مهمة جديدة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat-card label="جديدة" :value="$stats['new']" icon="grid" tone="slate" />
        <x-stat-card label="قيد التنفيذ" :value="$stats['in_progress']" icon="check" tone="brand" />
        <x-stat-card label="بانتظار المراجعة" :value="$stats['waiting_review']" icon="doc" tone="amber" />
        <x-stat-card label="متأخرة" :value="$stats['late']" icon="minus" tone="red" />
        <x-stat-card label="اكتملت اليوم" :value="$stats['completed_today']" icon="check" tone="emerald" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:flex-wrap lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالعنوان/الرقم..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="assignee" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الموظفين</option>
            @foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="priority" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الأولويات</option>
            @foreach ($priorityOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">المهمة</th>
                    <th class="px-4 py-3">العميل</th>
                    <th class="px-4 py-3">المسؤول</th><th class="px-4 py-3">التسليم</th>
                    <th class="px-4 py-3">الأولوية</th><th class="px-4 py-3">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tasks as $task)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $task->task_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">
                            <a href="{{ route('admin.tasks.show', $task) }}" class="hover:text-brand-600 hover:underline">{{ $task->title }}</a>
                            @if ($task->isLate())<span class="mr-1 text-xs text-red-600">(متأخرة)</span>@endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $task->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $task->primaryAssignee?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $task->due_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3"><x-badge :class="$task->priority->badgeClass()">{{ $task->priority->label() }}</x-badge></td>
                        <td class="px-4 py-3"><x-badge :class="$task->status->badgeClass()">{{ $task->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا مهام.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tasks->links() }}</div>

    @can('tasks.create')
        @include('livewire.partials.task-create-form')
    @endcan
</div>
