<div>
    @php $backRoute = auth()->user()->usesAdminExperience() ? 'admin.projects' : 'employee.projects'; @endphp
    <div class="mb-5 flex flex-wrap items-center gap-4">
        <a href="{{ route($backRoute) }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-800">{{ $project->name }}</h2>
            <p class="text-sm text-slate-500" dir="ltr">{{ $project->project_number }} · {{ $project->customer->name }}</p>
        </div>
        <div class="mr-auto flex items-center gap-2">
            <x-badge :class="$project->priority->badgeClass()">{{ $project->priority->label() }}</x-badge>
            <x-badge :class="$project->status->badgeClass()">{{ $project->status->label() }}</x-badge>
        </div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4">
        <div><p class="text-xs text-slate-500">مدير المشروع</p><p class="text-sm font-medium text-slate-700">{{ $project->projectManager?->full_name ?? '—' }}</p></div>
        <div><p class="text-xs text-slate-500">البداية</p><p class="text-sm font-medium text-slate-700" dir="ltr">{{ $project->start_date?->format('Y-m-d') ?? '—' }}</p></div>
        <div><p class="text-xs text-slate-500">التسليم</p><p class="text-sm font-medium text-slate-700" dir="ltr">{{ $project->due_date?->format('Y-m-d') ?? '—' }}</p></div>
        <div>
            <p class="text-xs text-slate-500">التقدم</p>
            <div class="mt-1 flex items-center gap-2">
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" style="width: {{ $project->progress() }}%"></div></div>
                <span class="text-xs text-slate-500">{{ $project->progress() }}%</span>
            </div>
        </div>
    </div>

    @php $tabs = ['overview' => 'نظرة عامة', 'tasks' => 'المهام', 'team' => 'الفريق', 'files' => 'الملفات', 'activity' => 'النشاط']; @endphp
    <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="setTab('{{ $key }}')" class="shrink-0 border-b-2 px-4 py-2.5 text-sm transition {{ $tab === $key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-2 font-semibold text-slate-800">الوصف</h3>
            <p class="text-sm text-slate-600">{{ $project->description ?: 'لا يوجد وصف.' }}</p>
        </div>
    @endif

    @if ($tab === 'tasks')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">المهمة</th><th class="px-4 py-3">المسؤول</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($tasks as $task)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $task->task_number }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route(auth()->user()->usesAdminExperience() ? 'admin.tasks.show' : 'employee.tasks.show', $task) }}" class="hover:text-brand-600 hover:underline">{{ $task->title }}</a></td>
                            <td class="px-4 py-3 text-slate-600">{{ $task->primaryAssignee?->full_name ?? '—' }}</td>
                            <td class="px-4 py-3"><x-badge :class="$task->status->badgeClass()">{{ $task->status->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا مهام مرئية.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'team')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">العضو</th><th class="px-4 py-3">الدور</th><th class="px-4 py-3">انضم</th>@if ($canManageMembers)<th class="px-4 py-3"></th>@endif</tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($members as $member)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-700">{{ $member->employee->full_name }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ $member->role ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $member->joined_at?->format('Y-m-d') ?? '—' }}</td>
                                @if ($canManageMembers)
                                    <td class="px-4 py-3"><button wire:click="removeMember({{ $member->employee_id }})" class="text-red-600 hover:underline">إزالة</button></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا أعضاء.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($canManageMembers)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="mb-3 font-semibold text-slate-800">إضافة عضو</h3>
                    <form wire:submit="addMember" class="space-y-3">
                        <select wire:model="newMemberId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— اختر موظفاً —</option>
                            @foreach ($availableEmployees as $emp)<option value="{{ $emp->id }}">{{ $emp->full_name }}</option>@endforeach
                        </select>
                        @error('newMemberId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <input type="text" wire:model="newMemberRole" placeholder="الدور (اختياري)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إضافة</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    @if ($tab === 'files')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الملف</th><th class="px-4 py-3">الحجم</th><th class="px-4 py-3">بواسطة</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($attachments as $att)
                            <tr><td class="px-4 py-3 font-medium text-slate-700">{{ $att->title }}</td><td class="px-4 py-3 text-slate-500" dir="ltr">{{ $att->humanSize() }}</td><td class="px-4 py-3 text-slate-500">{{ $att->uploader?->name ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-slate-400">لا ملفات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($canAttach)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="mb-3 font-semibold text-slate-800">رفع ملف</h3>
                    <form wire:submit="addAttachment" class="space-y-3">
                        <input type="text" wire:model="attachTitle" placeholder="العنوان (اختياري)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <input type="file" wire:model="attachFile" class="w-full text-sm">
                        @error('attachFile') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" wire:loading.attr="disabled">رفع</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    @if ($tab === 'activity')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">العملية</th><th class="px-4 py-3">بواسطة</th><th class="px-4 py-3">الوصف</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($activity as $log)
                        <tr><td class="px-4 py-3 text-slate-500" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</td><td class="px-4 py-3"><x-badge>{{ $log->action }}</x-badge></td><td class="px-4 py-3 text-slate-600">{{ $log->user?->name ?? 'النظام' }}</td><td class="px-4 py-3 text-slate-500">{{ $log->description }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا نشاط.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
