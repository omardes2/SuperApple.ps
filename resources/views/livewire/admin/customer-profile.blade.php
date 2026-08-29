<div>
    <div class="mb-5 flex items-center gap-4">
        <a href="{{ route('admin.customers') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <div class="flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-100 text-lg font-bold text-brand-700">{{ mb_substr($customer->name, 0, 1) }}</span>
            <div>
                <h2 class="text-xl font-bold text-slate-800">{{ $customer->name }}</h2>
                <p class="text-sm text-slate-500" dir="ltr">{{ $customer->customer_number }} · {{ $customer->phone }}</p>
            </div>
        </div>
        <div class="mr-auto"><x-badge :class="$customer->status->badgeClass()">{{ $customer->status->label() }}</x-badge></div>
    </div>

    @php
        $tabs = ['overview' => 'نظرة عامة', 'projects' => 'المشاريع', 'tasks' => 'المهام'];
        if ($canQuotations) $tabs['quotations'] = 'عروض الأسعار';
        if ($canInvoices) $tabs['invoices'] = 'الفواتير';
        if ($canPayments) $tabs['payments'] = 'الدفعات';
        if ($canSubscriptions) $tabs['subscriptions'] = 'الاشتراكات';
        if ($canWhatsapp) $tabs['communications'] = 'المراسلات';
        $tabs['attachments'] = 'المرفقات';
        $tabs['activity'] = 'سجل النشاط';
    @endphp
    @if ($canSendWhatsapp)
        <div class="mb-3 flex justify-end"><button wire:click="openReminder" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">إرسال تذكير دفع (واتساب)</button></div>
    @endif
    <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button wire:click="setTab('{{ $key }}')" class="shrink-0 border-b-2 px-4 py-2.5 text-sm transition {{ $tab === $key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        @isset($balance)
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-stat-card label="المستحق (Outstanding)" :value="'$'.number_format((float) $balance['outstanding_usd'], 2)" hint="USD — رصيد رسمي" icon="invoice" tone="amber" />
                <x-stat-card label="رصيد دائن غير مخصص" :value="'$'.number_format((float) $balance['unallocated_credit_usd'], 2)" hint="USD" icon="wallet" tone="emerald" />
                <x-stat-card label="صافي الرصيد (Net)" :value="'$'.number_format((float) $balance['net_balance_usd'], 2)" hint="مستحق − دائن" icon="cash" tone="brand" />
            </div>
            @if ($canStatement)
                <div class="mb-4">
                    <a href="{{ route('admin.customers.statement', $customer) }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline">عرض كشف الحساب الكامل (USD) ←</a>
                </div>
            @endif
        @endisset
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">بيانات التواصل</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">الشخص المسؤول</dt><dd class="text-slate-700">{{ $customer->contact_person ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الهاتف</dt><dd class="text-slate-700" dir="ltr">{{ $customer->phone ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">واتساب</dt><dd class="text-slate-700" dir="ltr">{{ $customer->whatsapp_number ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المدينة</dt><dd class="text-slate-700">{{ $customer->city ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">العنوان</dt><dd class="text-slate-700">{{ $customer->address ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الرقم الضريبي</dt><dd class="text-slate-700" dir="ltr">{{ $customer->tax_number ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المصدر</dt><dd class="text-slate-700">{{ $customer->source?->label() ?? '—' }}</dd></div>
                </dl>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">ملاحظات</h3>
                <p class="text-sm text-slate-600">{{ $customer->notes ?: 'لا توجد ملاحظات.' }}</p>
                @cannot('finance.view')
                    <p class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-400">المعلومات المالية (الفواتير، الدفعات، الرصيد) تظهر فقط لأصحاب الصلاحيات المالية في المراحل اللاحقة.</p>
                @endcannot
            </div>
        </div>
    @endif

    @if ($tab === 'projects')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">المشروع</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">المهام</th><th class="px-4 py-3">التسليم</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($projects as $project)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $project->project_number }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route('admin.projects.show', $project) }}" class="hover:text-brand-600 hover:underline">{{ $project->name }}</a></td>
                            <td class="px-4 py-3"><x-badge :class="$project->status->badgeClass()">{{ $project->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-slate-600">{{ $project->tasks_count }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $project->due_date?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا مشاريع.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'tasks')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">المهمة</th><th class="px-4 py-3">المسؤول</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">التسليم</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($tasks as $task)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $task->task_number }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route('admin.tasks.show', $task) }}" class="hover:text-brand-600 hover:underline">{{ $task->title }}</a></td>
                            <td class="px-4 py-3 text-slate-600">{{ $task->primaryAssignee?->full_name ?? '—' }}</td>
                            <td class="px-4 py-3"><x-badge :class="$task->status->badgeClass()">{{ $task->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $task->due_date?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا مهام.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'quotations' && $canQuotations)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الإجمالي USD</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($quotations as $q)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.quotations.show', $q) }}" class="hover:text-brand-600 hover:underline">{{ $q->quotation_number }}</a></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $q->quotation_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">${{ number_format((float) $q->total_usd, 2) }}</td>
                            <td class="px-4 py-3"><x-badge :class="$q->effectiveStatus()->badgeClass()">{{ $q->effectiveStatus()->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا عروض أسعار.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'invoices' && $canInvoices)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الإجمالي USD</th><th class="px-4 py-3">المتبقي USD</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($invoices as $inv)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.invoices.show', $inv) }}" class="hover:text-brand-600 hover:underline">{{ $inv->invoice_number }}</a></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $inv->invoice_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">${{ number_format((float) $inv->total_usd, 2) }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">${{ number_format((float) $inv->remaining_usd, 2) }}</td>
                            <td class="px-4 py-3"><x-badge :class="$inv->effectiveStatus()->badgeClass()">{{ $inv->effectiveStatus()->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا فواتير.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'payments' && $canPayments)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">ما يعادله USD</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $pay)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.payments.show', $pay) }}" class="hover:text-brand-600 hover:underline">{{ $pay->payment_number }}</a></td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $pay->payment_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 font-semibold text-slate-800" dir="ltr">{{ number_format((float) $pay->payment_amount, 2) }} {{ $pay->payment_currency->symbol() }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">${{ number_format((float) $pay->usd_equivalent, 2) }}</td>
                            <td class="px-4 py-3"><x-badge :class="$pay->status->badgeClass()">{{ $pay->status->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">لا دفعات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'attachments')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الملف</th><th class="px-4 py-3">الحجم</th><th class="px-4 py-3">بواسطة</th><th class="px-4 py-3">التاريخ</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($attachments as $att)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-700">{{ $att->title }}</td>
                                <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $att->humanSize() }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ $att->uploader?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $att->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا مرفقات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('customers.attachments')
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="mb-3 font-semibold text-slate-800">رفع مرفق</h3>
                    <form wire:submit="addAttachment" class="space-y-3">
                        <input type="text" wire:model="attachTitle" placeholder="عنوان (اختياري)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <input type="file" wire:model="attachFile" class="w-full text-sm">
                        @error('attachFile') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" wire:loading.attr="disabled">رفع</button>
                    </form>
                </div>
            @endcan
        </div>
    @endif

    @if ($tab === 'activity')
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">العملية</th><th class="px-4 py-3">بواسطة</th><th class="px-4 py-3">الوصف</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($activity as $log)
                        <tr>
                            <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3"><x-badge>{{ $log->action }}</x-badge></td>
                            <td class="px-4 py-3 text-slate-600">{{ $log->user?->name ?? 'النظام' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $log->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا نشاط.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'subscriptions' && ($canSubscriptions ?? false))
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الاسم</th><th class="px-4 py-3">الدورة</th>@if ($canSubscriptionPrices ?? false)<th class="px-4 py-3">القيمة</th>@endif<th class="px-4 py-3">الفوترة القادمة</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($subscriptions as $sub)
                        <tr>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $sub->subscription_number }}</td>
                            <td class="px-4 py-3 text-slate-800">{{ $sub->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $sub->billing_cycle->label() }}</td>
                            @if ($canSubscriptionPrices ?? false)<td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $sub->total_usd, 2) }} $</td>@endif
                            <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $sub->next_billing_date?->toDateString() ?? '—' }}</td>
                            <td class="px-4 py-3"><x-badge :class="$sub->status->badgeClass()">{{ $sub->status->label() }}</x-badge></td>
                            <td class="px-4 py-3"><a href="{{ route('admin.subscriptions.show', $sub) }}" class="text-brand-600 hover:underline">تفاصيل</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">لا اشتراكات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'communications' && ($canWhatsapp ?? false))
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">النص</th><th class="px-4 py-3">الفاتورة</th><th class="px-4 py-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($messages as $m)
                        <tr>
                            <td class="px-4 py-3 text-slate-400" dir="ltr">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ \Illuminate\Support\Str::limit($m->message_body, 70) }}</td>
                            <td class="px-4 py-3">@if ($m->invoice)<span class="font-mono text-xs text-slate-500" dir="ltr">{{ $m->invoice->invoice_number }}</span>@else—@endif</td>
                            <td class="px-4 py-3"><x-badge :class="$m->status->badgeClass()">{{ $m->status->label() }}</x-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">لا مراسلات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($canSendWhatsapp ?? false)
        <x-modal show="showReminder" title="إرسال تذكير دفع عبر واتساب" maxWidth="max-w-xl">
            <p class="mb-2 text-xs text-slate-500">المبلغ بالدولار هو المرجع. القيمة بالشيكل تقديرية وفق آخر سعر صرف.</p>
            @error('reminder')<p class="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-600">{{ $message }}</p>@enderror
            <textarea wire:model="reminderBody" rows="8" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>@error('reminderBody')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showReminder=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="sendReminder" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">إرسال</button></div>
        </x-modal>
    @endif
</div>
