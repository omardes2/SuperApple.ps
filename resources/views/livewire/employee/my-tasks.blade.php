<div class="space-y-5">
    <x-page-header title="مهامي" subtitle="المهام التي أنا مسؤول عنها أو مشارك فيها">
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
            'in_progress' => 'قيد التنفيذ', 'completed' => 'مكتملة',
        ];
        $memberStatuses = \App\Enums\TaskMemberStatus::class;
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
            @php
                $myMember = $actingEmployeeId ? $task->activeMembers->firstWhere('id', $actingEmployeeId) : null;
                $myStatus = $myMember ? \App\Enums\TaskMemberStatus::from($myMember->pivot->status) : null;
            @endphp
            <a href="{{ route('employee.tasks.show', $task) }}" class="block rounded-xl border border-slate-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800">{{ $task->title }}</p>
                        <p class="mt-0.5 text-xs text-slate-500" dir="ltr">{{ $task->task_number }}</p>
                        @if ($task->customer)
                            <p class="mt-0.5 text-xs text-slate-600">{{ $task->customer->name }}</p>
                        @endif
                        @if ($task->services->isNotEmpty())
                            <p class="mt-1 text-xs text-slate-400">{{ $task->services->pluck('name')->join(' + ') }}</p>
                        @endif
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <div class="flex items-center gap-2">
                            <x-badge :class="$task->priority->badgeClass()">{{ $task->priority->label() }}</x-badge>
                            <x-badge :class="$task->status->badgeClass()">{{ $task->status->label() }}</x-badge>
                        </div>
                        @if ($myStatus)
                            <span class="text-xs {{ $myStatus === $memberStatuses::Completed ? 'text-emerald-600' : ($myStatus === $memberStatuses::InProgress ? 'text-brand-600' : 'text-slate-400') }}">
                                حالتي: {{ $myStatus->label() }}
                            </span>
                        @endif
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
                {{-- Title --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">العنوان *</label>
                    <input type="text" wire:model="title" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="تصميم حملة رمضان">
                    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Description --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الوصف</label>
                    <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Customer searchable combobox --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">العميل *</label>
                    @if ($customer_id)
                        <div class="flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm">
                            <span class="font-medium text-slate-800">{{ $customerSearch }}</span>
                            <button type="button" wire:click="clearCustomer" class="text-xs text-red-500 hover:underline">تغيير</button>
                        </div>
                    @else
                        <input type="text" wire:model.live.debounce.300ms="customerSearch" placeholder="🔎 ابحث بالاسم / رقم العميل / واتساب..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @if ($customerResults->isNotEmpty())
                            <ul class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-sm">
                                @foreach ($customerResults as $c)
                                    <li>
                                        <button type="button" wire:click="selectCustomer({{ $c->id }})" class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-right hover:bg-slate-50">
                                            <span class="font-medium text-slate-800">{{ $c->name }}</span>
                                            <span class="text-xs text-slate-400" dir="ltr">{{ $c->customer_number }}@if ($c->whatsapp_number) · {{ $c->whatsapp_number }}@endif</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                    @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Services multi-select --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الخدمات *</label>
                    @if ($selectedServices->isNotEmpty())
                        <div class="mb-2 flex flex-wrap gap-2">
                            @foreach ($selectedServices as $s)
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-xs text-brand-700">
                                    {{ $s->name }}
                                    <button type="button" wire:click="toggleService({{ $s->id }})" class="text-brand-500 hover:text-red-600">✕</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <input type="text" wire:model.live.debounce.300ms="serviceSearch" placeholder="ابحث عن خدمة..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @if ($serviceResults->isNotEmpty())
                        <ul class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-sm">
                            @foreach ($serviceResults as $s)
                                @php $isSel = in_array($s->id, $selectedServiceIds, true); @endphp
                                <li>
                                    <button type="button" wire:click="toggleService({{ $s->id }})" class="flex w-full items-center justify-between px-3 py-2 text-right hover:bg-slate-50 {{ $isSel ? 'bg-brand-50/60' : '' }}">
                                        <span class="text-slate-800">{{ $s->name }}@if ($s->category)<span class="mr-1 text-xs text-slate-400">· {{ $s->category }}</span>@endif</span>
                                        @if ($isSel)<span class="text-xs text-brand-600">✓ مختارة</span>@endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @error('selectedServiceIds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Funded-ads campaign budget (conditional) --}}
                @if ($adBudgetRequired)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <label class="mb-1 block text-sm font-medium text-amber-800">قيمة الإعلانات الممولة للحملة *</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" wire:model="ad_budget_amount" dir="ltr" placeholder="500" class="flex-1 rounded-lg border border-amber-300 px-3 py-2 text-sm">
                            <select wire:model="ad_budget_currency" class="rounded-lg border border-amber-300 px-2 py-2 text-sm">
                                <option value="ILS">ILS</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <p class="mt-1 text-xs text-amber-700">ميزانية الحملة الإعلانية فقط — ليست سعر خدمة ولا تدخل في الحسابات المالية.</p>
                        @error('ad_budget_amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Priority + dates --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">الأولوية</label>
                        <select wire:model="task_priority" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($priorityOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ البداية</label>
                        <input type="date" wire:model="start_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('start_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ الانتهاء</label>
                        <input type="date" wire:model="due_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('due_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="text-xs text-slate-400">ستكون مسؤول المهمة تلقائياً وضمن فريقها. يمكنك إضافة مشاركين وقائمة تحقق ومرفقات من صفحة المهمة.</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إنشاء</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
