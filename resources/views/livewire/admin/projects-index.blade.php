<div>
    <x-page-header title="المشاريع" subtitle="إدارة مشاريع العملاء">
        <x-slot:actions>
            @can('projects.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مشروع جديد</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="المشاريع النشطة" :value="$stats['active']" icon="folder" tone="emerald" />
        <x-stat-card label="المتأخرة" :value="$stats['late']" icon="minus" tone="red" />
        <x-stat-card label="تحت المراجعة" :value="$stats['under_review']" icon="doc" tone="amber" />
        <x-stat-card label="مكتملة هذا الشهر" :value="$stats['completed_month']" icon="check" tone="brand" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:flex-wrap lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم/الرقم..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل العملاء</option>
            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <select wire:model.live="manager" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل المدراء</option>
            @foreach ($managers as $m)<option value="{{ $m->id }}">{{ $m->full_name }}</option>@endforeach
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
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">المشروع</th>
                    <th class="px-4 py-3">العميل</th><th class="px-4 py-3">المدير</th>
                    <th class="px-4 py-3">التسليم</th><th class="px-4 py-3">التقدم</th>
                    <th class="px-4 py-3">المهام</th><th class="px-4 py-3">الأولوية</th><th class="px-4 py-3">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($projects as $project)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $project->project_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">
                            <a href="{{ route('admin.projects.show', $project) }}" class="hover:text-brand-600 hover:underline">{{ $project->name }}</a>
                            @if ($project->isLate())<span class="mr-1 text-xs text-red-600">(متأخر)</span>@endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $project->customer->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $project->projectManager?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $project->due_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" style="width: {{ $project->progress() }}%"></div></div>
                                <span class="text-xs text-slate-500">{{ $project->progress() }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $project->tasks_count }}</td>
                        <td class="px-4 py-3"><x-badge :class="$project->priority->badgeClass()">{{ $project->priority->label() }}</x-badge></td>
                        <td class="px-4 py-3"><x-badge :class="$project->status->badgeClass()">{{ $project->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">لا مشاريع.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $projects->links() }}</div>

    <x-modal show="showForm" :title="$editingId ? 'تعديل مشروع' : 'مشروع جديد'" maxWidth="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">اسم المشروع</label>
                    <input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">العميل</label>
                    <select wire:model="customer_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— اختر —</option>
                        @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">نوع المشروع</label>
                    <input type="text" wire:model="project_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">مدير المشروع</label>
                    <select wire:model="project_manager_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— بدون —</option>
                        @foreach ($managers as $m)<option value="{{ $m->id }}">{{ $m->full_name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">القسم</label>
                    <select wire:model="department_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— بدون —</option>
                        @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الأولوية</label>
                    <select wire:model="project_priority" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($priorityOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الحالة</label>
                    <select wire:model="project_status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
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
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">الوصف</label>
                    <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
