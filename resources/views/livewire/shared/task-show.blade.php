<div>
    @php
        $isAdmin = auth()->user()->usesAdminExperience();
        $backRoute = $isAdmin ? 'admin.tasks' : 'employee.tasks';
    @endphp

    <div class="mb-5 flex flex-wrap items-center gap-4">
        <a href="{{ route($backRoute) }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-800">{{ $task->title }}</h2>
            <p class="text-sm text-slate-500" dir="ltr">{{ $task->task_number }}</p>
        </div>
        <div class="mr-auto flex items-center gap-2">
            <x-badge :class="$task->priority->badgeClass()">{{ $task->priority->label() }}</x-badge>
            <x-badge :class="$task->status->badgeClass()">{{ $task->status->label() }}</x-badge>
        </div>
    </div>

    @error('workflow') <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    {{-- Workflow actions --}}
    @if (count($actions))
        <div class="mb-5 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-4">
            @foreach ($actions as $status => $label)
                @php
                    $tone = match ($status) {
                        'completed' => 'bg-emerald-600 hover:bg-emerald-700',
                        'changes_requested' => 'bg-amber-600 hover:bg-amber-700',
                        'cancelled' => 'bg-red-600 hover:bg-red-700',
                        default => 'bg-brand-600 hover:bg-brand-700',
                    };
                @endphp
                <button wire:click="startTransition('{{ $status }}')"
                        @if($status==='cancelled') wire:confirm="إلغاء هذه المهمة؟" @endif
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white {{ $tone }}">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Left: details + tabs --}}
        <div class="space-y-5 lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-2 font-semibold text-slate-800">الوصف</h3>
                <p class="text-sm text-slate-600">{{ $task->description ?: 'لا يوجد وصف.' }}</p>
            </div>

            {{-- Checklist --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">قائمة التحقق</h3>
                <ul class="space-y-2">
                    @forelse ($checklist as $item)
                        <li class="flex items-center gap-2 text-sm">
                            @can('tasks.checklist')
                                <input type="checkbox" @checked($item->is_completed) wire:click="toggleChecklistItem({{ $item->id }})" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            @else
                                <input type="checkbox" @checked($item->is_completed) disabled class="rounded border-slate-300">
                            @endcan
                            <span class="{{ $item->is_completed ? 'text-slate-400 line-through' : 'text-slate-700' }}">{{ $item->title }}</span>
                            @can('tasks.checklist')
                                <button wire:click="deleteChecklistItem({{ $item->id }})" class="mr-auto text-xs text-red-500 hover:underline">حذف</button>
                            @endcan
                        </li>
                    @empty
                        <li class="text-sm text-slate-400">لا عناصر.</li>
                    @endforelse
                </ul>
                @can('tasks.checklist')
                    <form wire:submit="addChecklistItem" class="mt-3 flex gap-2">
                        <input type="text" wire:model="newChecklistItem" placeholder="عنصر جديد" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button type="submit" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900">+</button>
                    </form>
                    @error('newChecklistItem') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endcan
            </div>

            {{-- Comments --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">التعليقات</h3>
                <div class="space-y-3">
                    @forelse ($comments as $comment)
                        <div class="rounded-lg bg-slate-50 p-3">
                            <div class="mb-1 flex items-center justify-between text-xs text-slate-400">
                                <span class="font-medium text-slate-600">{{ $comment->user->name }}</span>
                                <span dir="ltr">{{ $comment->created_at->format('Y-m-d H:i') }}</span>
                            </div>
                            <p class="text-sm text-slate-700">{{ $comment->comment }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">لا تعليقات بعد.</p>
                    @endforelse
                </div>
                @can('tasks.comment')
                    <form wire:submit="addComment" class="mt-3">
                        <textarea wire:model="newComment" rows="2" placeholder="أضف تعليقاً..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                        @error('newComment') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <div class="mt-2 flex justify-end"><button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إرسال</button></div>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Right: meta + assignees + attachments + history --}}
        <div class="space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">التفاصيل</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">العميل</dt><dd class="text-slate-700">{{ $task->customer?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المسؤول</dt><dd class="text-slate-700">{{ $task->primaryAssignee?->full_name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">التسليم</dt><dd class="text-slate-700" dir="ltr">{{ $task->due_date?->format('Y-m-d') ?? '—' }}</dd></div>
                </dl>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">المشاركون</h3>
                <ul class="space-y-2 text-sm">
                    @forelse ($task->assignees as $assignee)
                        <li class="flex items-center justify-between">
                            <span class="text-slate-700">{{ $assignee->full_name }}</span>
                            @can('tasks.assign')<button wire:click="removeAssignee({{ $assignee->id }})" class="text-xs text-red-500 hover:underline">إزالة</button>@endcan
                        </li>
                    @empty
                        <li class="text-slate-400">لا مشاركين إضافيين.</li>
                    @endforelse
                </ul>
                @can('tasks.assign')
                    <form wire:submit="addAssignee" class="mt-3 flex gap-2">
                        <select wire:model="newAssigneeId" class="flex-1 rounded-lg border border-slate-300 px-2 py-2 text-sm">
                            <option value="">— موظف —</option>
                            @foreach ($availableEmployees as $emp)<option value="{{ $emp->id }}">{{ $emp->full_name }}</option>@endforeach
                        </select>
                        <button type="submit" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900">+</button>
                    </form>
                    @error('newAssigneeId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endcan
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">المرفقات</h3>
                <ul class="space-y-2 text-sm">
                    @forelse ($attachments as $att)
                        <li class="flex items-center justify-between"><span class="text-slate-700">{{ $att->title }}</span><span class="text-xs text-slate-400" dir="ltr">{{ $att->humanSize() }}</span></li>
                    @empty
                        <li class="text-slate-400">لا مرفقات.</li>
                    @endforelse
                </ul>
                @can('tasks.attachments')
                    <form wire:submit="addAttachment" class="mt-3 space-y-2">
                        <input type="file" wire:model="attachFile" class="w-full text-sm">
                        @error('attachFile') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="w-full rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900" wire:loading.attr="disabled">رفع مرفق</button>
                    </form>
                @endcan
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">سجل الحالة</h3>
                <ol class="space-y-2 text-sm">
                    @forelse ($history as $h)
                        <li class="border-r-2 border-slate-200 pr-3">
                            <p class="text-slate-700">{{ $h->from_status?->label() ?? 'إنشاء' }} ← {{ $h->to_status->label() }}</p>
                            <p class="text-xs text-slate-400"><span dir="ltr">{{ $h->created_at?->format('Y-m-d H:i') }}</span> · {{ $h->changedBy?->name ?? 'النظام' }}</p>
                            @if ($h->reason)<p class="text-xs text-slate-500">{{ $h->reason }}</p>@endif
                        </li>
                    @empty
                        <li class="text-slate-400">لا سجل بعد.</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </div>

    <x-modal show="showReason" title="سبب الإجراء">
        <form wire:submit="confirmReason" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">السبب</label>
                <textarea wire:model="reason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('workflow') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showReason = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">تأكيد</button>
            </div>
        </form>
    </x-modal>
</div>
