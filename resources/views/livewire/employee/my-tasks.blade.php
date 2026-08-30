<div class="space-y-5">
    <x-page-header title="مهامي" subtitle="المهام المسندة إليك">
        <x-slot:actions>
            @if ($canCreate)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مهمة جديدة</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

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

    @if ($canCreate)
        <x-modal show="showForm" title="مهمة جديدة" maxWidth="max-w-xl">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">العنوان</label>
                    <input type="text" wire:model="title" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الوصف (اختياري)</label>
                    <textarea wire:model="description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @if ($customers->isNotEmpty())
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">العميل (اختياري)</label>
                            <select wire:model="customer_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">— بدون —</option>
                                @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">الأولوية</label>
                        <select wire:model="task_priority" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($priorityOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ البداية</label>
                        <input type="date" wire:model="start_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ التسليم</label>
                        <input type="date" wire:model="due_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('due_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-xs text-slate-400">ستُسند المهمة إليك تلقائياً. يمكنك إضافة قائمة تحقق ومرفقات من صفحة المهمة بعد إنشائها.</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إنشاء</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
