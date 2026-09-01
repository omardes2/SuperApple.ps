<div>
    <x-page-header :title="'دفعة '.$payment->payment_number" subtitle="القيمة الرسمية بالدولار الأمريكي (USD)">
        <x-slot:actions>
            <a href="{{ route('admin.payments') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
            @if ($payment->isPosted())
                @can('print', $payment)
                    <a href="{{ route('admin.payments.receipt', $payment) }}" target="_blank" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إيصال (طباعة)</a>
                @endcan
            @endif
            @can('cancel', $payment)
                @if (! $payment->isCancelled())
                    <button wire:click="openCancel" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">إلغاء الدفعة</button>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-4 flex items-center gap-3">
        <x-badge :class="$payment->status->badgeClass()">{{ $payment->status->label() }}</x-badge>
        @if ($payment->isPosted())<span class="text-xs text-slate-400" dir="ltr">مُرحّلة في {{ $payment->posted_at?->format('Y-m-d H:i') }}</span>@endif
        @if ($payment->isCancelled())<span class="text-xs text-red-500">سبب الإلغاء: {{ $payment->cancellation_reason }}</span>@endif
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ---- Main: form or read-only ---- --}}
        <div class="lg:col-span-2 space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-4 font-semibold text-slate-800">بيانات الدفعة</h3>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">العميل</label>
                        @if ($customer_id)
                            <div class="flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm">
                                <span class="font-medium text-slate-800">{{ $selectedCustomerName }}</span>
                                @if ($canEdit)
                                    <button type="button" wire:click="clearCustomer" class="text-xs text-red-500 hover:underline">تغيير</button>
                                @endif
                            </div>
                        @elseif ($canEdit)
                            <input type="text" wire:model.live.debounce.300ms="customerSearch"
                                   placeholder="🔎 ابحث بالاسم / رقم العميل / واتساب..."
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @if ($customerResults->isNotEmpty())
                                <ul class="mt-1 max-h-56 overflow-y-auto rounded-lg border border-slate-200 bg-white text-sm shadow-sm">
                                    @foreach ($customerResults as $c)
                                        <li>
                                            <button type="button" wire:click="selectCustomer({{ $c->id }})"
                                                    class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-right hover:bg-slate-50">
                                                <span class="font-medium text-slate-800">{{ $c->name }}</span>
                                                <span class="text-xs text-slate-400" dir="ltr">{{ $c->customer_number }}@if ($c->whatsapp_number) · {{ $c->whatsapp_number }}@endif</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (trim($customerSearch) !== '')
                                <p class="mt-1 text-xs text-slate-400">لا يوجد عميل مطابق.</p>
                            @endif
                        @else
                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">{{ $selectedCustomerName ?? '—' }}</div>
                        @endif
                        @error('customer_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">تاريخ الدفعة</label>
                        <input type="date" wire:model.live="payment_date" @disabled(! $canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                        @error('payment_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">العملة</label>
                        <select wire:model.live="payment_currency" @disabled(! $canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                            @foreach ($currencyOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">المبلغ المستلم</label>
                        <input type="number" step="0.01" min="0" wire:model.live="payment_amount" @disabled(! $canEdit) dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                        @error('payment_amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">سعر صرف الدفعة (1 USD = ? ILS)</label>
                        <input type="number" step="0.000001" min="0" wire:model.live="exchange_rate" @disabled(! $canEdit) placeholder="مثال: 3.05" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                        <p class="mt-1 text-xs text-slate-400">يُدخل يدوياً لكل دفعة، ومستقل تماماً عن سعر صرف الفاتورة.</p>
                        @error('exchange_rate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">طريقة الدفع</label>
                        <select wire:model.live="payment_method" @disabled(! $canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                            @foreach ($methodOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">إيداع في <span class="text-red-500">*</span></label>
                        <select wire:model.live="account_id" @disabled(! $canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                            <option value="">— اختر الصندوق/الحساب —</option>
                            @foreach ($depositAccounts as $acc)<option value="{{ $acc->id }}">{{ $acc->name }} ({{ $acc->currency }})</option>@endforeach
                        </select>
                        @if ($depositAccounts->isEmpty())
                            <p class="mt-1 text-xs text-amber-600">لا يوجد حساب نقدي/بنكي نشط بعملة {{ $payment_currency }}. أنشئ حساباً من صفحة الصناديق والبنوك.</p>
                        @else
                            <p class="mt-1 text-xs text-slate-400">طريقة الدفع مستقلة عن حساب الإيداع الفعلي.</p>
                        @endif
                        @error('account_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">رقم المرجع (اختياري)</label>
                        <input type="text" wire:model.live="reference_number" @disabled(! $canEdit) class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-slate-600">ملاحظات</label>
                        <textarea wire:model.live="notes" @disabled(! $canEdit) rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50"></textarea>
                    </div>
                </div>

                @if ($canEdit)
                    <div class="mt-4 flex justify-end">
                        <button wire:click="save" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">حفظ المسودة</button>
                    </div>
                @endif
            </div>

            {{-- ---- Allocation editor (draft only) ---- --}}
            @if ($payment->isDraft())
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold text-slate-800">تخصيص الدفعة على الذمم</h3>
                        <div class="flex flex-wrap gap-2">
                            <button wire:click="autoAllocate" class="rounded-lg border border-brand-300 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50">تخصيص تلقائي (الأقدم)</button>
                            @if ($openOpeningBalance)
                                <button wire:click="addOpeningBalanceRow({{ $openOpeningBalance->id }})" class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50">+ رصيد افتتاحي</button>
                            @endif
                            <button wire:click="addAllocationRow" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50">+ سطر</button>
                        </div>
                    </div>

                    @if ($openOpeningBalance)
                        <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">رصيد افتتاحي مستحق: <b dir="ltr">${{ number_format((float) $openOpeningBalance->remaining_usd, 2) }}</b> — يمكن تخصيص الدفعة عليه.</p>
                    @endif

                    @if ($openInvoices->isEmpty() && ! $openOpeningBalance)
                        <p class="text-sm text-slate-400">لا توجد ذمم مفتوحة لهذا العميل.</p>
                    @else
                        <div class="space-y-3">
                            @forelse ($allocations as $i => $row)
                                <div class="flex flex-col gap-2 rounded-lg border border-slate-200 p-3 sm:flex-row sm:items-center" wire:key="alloc-{{ $i }}">
                                    @if (! empty($row['opening_balance_id']))
                                        <div class="flex-1 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">رصيد افتتاحي</div>
                                    @else
                                        <select wire:model.live="allocations.{{ $i }}.invoice_id" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            <option value="">— اختر فاتورة —</option>
                                            @foreach ($openInvoices as $inv)
                                                <option value="{{ $inv->id }}">{{ $inv->invoice_number }} — متبقٍ ${{ number_format((float) $inv->remaining_usd, 2) }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <input type="number" step="0.01" min="0" wire:model.live="allocations.{{ $i }}.allocated_usd" placeholder="USD" dir="ltr" class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <button wire:click="removeAllocationRow({{ $i }})" class="rounded-lg border border-red-200 px-3 py-2 text-xs text-red-600 hover:bg-red-50">حذف</button>
                                </div>
                            @empty
                                <p class="text-sm text-slate-400">أضف سطر تخصيص أو استخدم التخصيص التلقائي. المبلغ غير المخصص يُحفظ كرصيد دائن للعميل.</p>
                            @endforelse
                        </div>
                    @endif

                    <div class="mt-4 grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-3 text-center text-sm">
                        <div><p class="text-xs text-slate-500">قيمة الدفعة USD</p><p class="font-bold text-slate-800" dir="ltr">${{ number_format((float) $usdPreview, 2) }}</p></div>
                        <div><p class="text-xs text-slate-500">المخصّص</p><p class="font-bold text-slate-800" dir="ltr">${{ number_format((float) $allocatedTotal, 2) }}</p></div>
                        <div><p class="text-xs text-slate-500">رصيد دائن (غير مخصص)</p><p class="font-bold {{ (float) $unallocatedPreview < 0 ? 'text-red-600' : 'text-emerald-700' }}" dir="ltr">${{ number_format((float) $unallocatedPreview, 2) }}</p></div>
                    </div>

                    @if ($canPost)
                        <div class="mt-4 flex justify-end">
                            <button wire:click="post" wire:confirm="سيتم ترحيل الدفعة وقفلها نهائياً. متابعة؟" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">ترحيل الدفعة</button>
                        </div>
                    @endif
                </div>
            @else
                {{-- ---- Posted/cancelled allocations (read-only, with exchange difference) ---- --}}
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="mb-4 font-semibold text-slate-800">التخصيصات وفروقات الصرف</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">الفاتورة</th><th class="px-3 py-2">المخصّص USD</th>
                                    <th class="px-3 py-2">سعر الفاتورة</th><th class="px-3 py-2">سعر الدفعة</th>
                                    <th class="px-3 py-2">فرق الصرف ILS</th><th class="px-3 py-2">الحالة</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($payment->allocations as $alloc)
                                    <tr class="{{ $alloc->status === 'reversed' ? 'text-slate-400 line-through' : '' }}">
                                        <td class="px-3 py-2 font-mono" dir="ltr">
                                            @if ($alloc->invoice)
                                                <a href="{{ route('admin.invoices.show', $alloc->invoice) }}" class="hover:text-brand-600 hover:underline">{{ $alloc->invoice->invoice_number }}</a>
                                            @elseif ($alloc->opening_balance_id)
                                                <span class="text-amber-700">رصيد افتتاحي</span>
                                            @else — @endif
                                        </td>
                                        <td class="px-3 py-2" dir="ltr">${{ number_format((float) $alloc->allocated_usd, 2) }}</td>
                                        <td class="px-3 py-2" dir="ltr">{{ $alloc->invoice_exchange_rate }}</td>
                                        <td class="px-3 py-2" dir="ltr">{{ $alloc->payment_exchange_rate }}</td>
                                        <td class="px-3 py-2 font-semibold {{ (float) $alloc->exchange_difference_ils > 0 ? 'text-emerald-700' : ((float) $alloc->exchange_difference_ils < 0 ? 'text-red-600' : 'text-slate-500') }}" dir="ltr">
                                            {{ (float) $alloc->exchange_difference_ils > 0 ? '+' : '' }}{{ number_format((float) $alloc->exchange_difference_ils, 2) }} ₪
                                        </td>
                                        <td class="px-3 py-2 text-xs">{{ $alloc->status === 'reversed' ? 'معكوس' : 'نشط' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-3 py-6 text-center text-slate-400">لا تخصيصات — الدفعة كاملة رصيد دائن.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- ---- Sidebar: summary ---- --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">ملخص</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">المبلغ المستلم</dt><dd class="font-semibold" dir="ltr">{{ number_format((float) $payment->payment_amount, 2) }} {{ $payment->payment_currency->symbol() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">ما يعادله USD</dt><dd class="font-semibold text-slate-800" dir="ltr">${{ number_format((float) ($payment->isDraft() ? $usdPreview : $payment->usd_equivalent), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">سعر الصرف</dt><dd dir="ltr">{{ $payment->exchange_rate ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الطريقة</dt><dd>{{ $payment->payment_method->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الحساب المستلم</dt><dd class="font-medium">@if ($receivedAccount)<a href="{{ route('admin.cash-banks') }}" class="text-brand-600 hover:underline">{{ $receivedAccount->name }}</a>@else<span class="text-amber-600">غير محدد</span>@endif</dd></div>
                    @unless ($payment->isDraft())
                        <div class="flex justify-between border-t border-slate-100 pt-2"><dt class="text-slate-500">مخصّص</dt><dd dir="ltr">${{ number_format((float) $payment->allocatedUsd(), 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">رصيد دائن</dt><dd class="font-semibold text-emerald-700" dir="ltr">${{ number_format((float) $payment->unallocatedUsd(), 2) }}</dd></div>
                    @endunless
                    <div class="flex justify-between"><dt class="text-slate-500">استلمها</dt><dd>{{ $payment->receivedBy?->name ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>
    </div>

    {{-- ---- Cancel modal ---- --}}
    <x-modal show="showCancel" title="إلغاء الدفعة">
        <p class="mb-3 text-sm text-slate-600">سيتم عكس جميع التخصيصات واستعادة أرصدة الفواتير. لا يمكن التراجع.</p>
        <label class="mb-1 block text-sm text-slate-600">سبب الإلغاء</label>
        <textarea wire:model="cancelReason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        @error('cancelReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="$wire.showCancel = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">تراجع</button>
            <button wire:click="confirmCancel" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">تأكيد الإلغاء</button>
        </div>
    </x-modal>
</div>
