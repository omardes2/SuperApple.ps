<div>
    @php $memberStatuses = \App\Enums\TaskMemberStatus::class; @endphp

    <div class="mb-5 flex flex-wrap items-center gap-4">
        <a href="{{ route('employee.tasks') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
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

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('workflow') <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    {{-- Personal execution button (current member only) --}}
    @if ($isMember)
        <div class="mb-5 rounded-xl border border-slate-200 bg-white p-4">
            @if ($myStatus === $memberStatuses::NotStarted)
                <button wire:click="startMine" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">بدء المهمة</button>
            @elseif ($myStatus === $memberStatuses::InProgress)
                <button wire:click="completeMine" class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700">إتمام المهمة</button>
            @else
                <p class="flex items-center gap-2 text-sm font-medium text-emerald-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                    تم إتمام عملك في هذه المهمة
                </p>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Left --}}
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
                            @if ($canChecklist)
                                <input type="checkbox" @checked($item->is_completed) wire:click="toggleChecklistItem({{ $item->id }})" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            @else
                                <input type="checkbox" @checked($item->is_completed) disabled class="rounded border-slate-300">
                            @endif
                            <span class="{{ $item->is_completed ? 'text-slate-400 line-through' : 'text-slate-700' }}">{{ $item->title }}</span>
                            @if ($canChecklist)<button wire:click="deleteChecklistItem({{ $item->id }})" class="mr-auto text-xs text-red-500 hover:underline">حذف</button>@endif
                        </li>
                    @empty
                        <li class="text-sm text-slate-400">لا عناصر.</li>
                    @endforelse
                </ul>
                @if ($canChecklist)
                    <form wire:submit="addChecklistItem" class="mt-3 flex gap-2">
                        <input type="text" wire:model="newChecklistItem" placeholder="عنصر جديد" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button type="submit" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900">+</button>
                    </form>
                    @error('newChecklistItem') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif
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
                @if ($canComment)
                    <form wire:submit="addComment" class="mt-3">
                        <textarea wire:model="newComment" rows="2" placeholder="أضف تعليقاً..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                        @error('newComment') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <div class="mt-2 flex justify-end"><button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إرسال</button></div>
                    </form>
                @endif
            </div>
        </div>

        {{-- Right --}}
        <div class="space-y-5">
            {{-- Details --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">التفاصيل</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">العميل</dt><dd class="text-slate-700">{{ $task->customer?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">الخدمات</dt>
                        <dd class="text-left text-slate-700">
                            @forelse ($task->services as $s)<div>{{ $s->name }}</div>@empty —@endforelse
                        </dd>
                    </div>
                    @if ($task->hasAdBudgetService() && $task->ad_budget_amount !== null)
                        <div class="flex justify-between"><dt class="text-slate-500">ميزانية الإعلانات</dt><dd class="font-medium text-slate-800" dir="ltr">{{ number_format((float) $task->ad_budget_amount, 2) }} {{ $task->ad_budget_currency }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">الأولوية</dt><dd class="text-slate-700">{{ $task->priority->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">البداية</dt><dd class="text-slate-700" dir="ltr">{{ $task->start_date?->format('Y-m-d') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">التسليم</dt><dd class="text-slate-700" dir="ltr">{{ $task->due_date?->format('Y-m-d') ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Task team --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">فريق المهمة</h3>
                <ul class="space-y-3 text-sm">
                    @foreach ($team as $member)
                        @php
                            $mStatus = \App\Enums\TaskMemberStatus::from($member->pivot->status);
                            $isOwner = (int) $task->primary_assignee_id === (int) $member->id || $member->pivot->role === 'owner';
                        @endphp
                        <li class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-2.5 w-2.5 rounded-full {{ $mStatus->dotClass() }}"></span>
                                <div>
                                    <p class="text-slate-800">{{ $member->full_name }}</p>
                                    <p class="text-xs text-slate-400">{{ $isOwner ? 'مسؤول' : 'مشارك' }} · {{ $mStatus->label() }}</p>
                                </div>
                            </div>
                            @if ($canManageTeam && ! $isOwner)
                                <button wire:click="removeParticipant({{ $member->id }})" wire:confirm="إزالة هذا المشارك؟" class="text-xs text-red-500 hover:underline">إزالة</button>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($canManageTeam && $task->status->isOpen())
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <input type="text" wire:model.live.debounce.300ms="participantSearch" placeholder="+ إضافة مشارك (اسم / رقم وظيفي / مسمى)..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('participant') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($participantResults->isNotEmpty())
                            <ul class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-sm">
                                @foreach ($participantResults as $emp)
                                    <li>
                                        <button type="button" wire:click="addParticipant({{ $emp->id }})" class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-right hover:bg-slate-50">
                                            <span class="text-slate-800">{{ $emp->full_name }}</span>
                                            <span class="text-xs text-slate-400" dir="ltr">{{ $emp->employee_number }}@if ($emp->job_title) · {{ $emp->job_title }}@endif</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Attachments --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">المرفقات</h3>
                <ul class="space-y-2 text-sm">
                    @forelse ($attachments as $att)
                        <li class="flex items-center justify-between"><span class="text-slate-700">{{ $att->title }}</span><span class="text-xs text-slate-400" dir="ltr">{{ $att->humanSize() }}</span></li>
                    @empty
                        <li class="text-slate-400">لا مرفقات.</li>
                    @endforelse
                </ul>
                @if ($canAttach)
                    <form wire:submit="addAttachment" class="mt-3 space-y-2">
                        <input type="file" wire:model="attachFile" class="w-full text-sm">
                        @error('attachFile') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="w-full rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-900" wire:loading.attr="disabled">رفع مرفق</button>
                    </form>
                @endif
            </div>

            {{-- Status history --}}
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
</div>
