<div>
    <x-page-header title="الفواتير" subtitle="الرصيد الرسمي بالدولار الأمريكي (USD)">
        <x-slot:actions>
            @can('create', \App\Models\Invoice::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ فاتورة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat-card label="مسودات" :value="$stats['draft']" icon="doc" tone="slate" />
        <x-stat-card label="صادرة هذا الشهر" :value="$stats['issued_month']" icon="invoice" tone="brand" />
        <x-stat-card label="مفوتر هذا الشهر" :value="'$'.number_format((float) $stats['invoiced_month'], 2)" :hint="'≈ '.number_format((float) $stats['invoiced_month_ils'], 2).' ₪'" icon="cash" tone="emerald" />
        <x-stat-card label="المستحق (Outstanding)" :value="'$'.number_format((float) $stats['outstanding'], 2)" :hint="'≈ '.number_format((float) $stats['outstanding_ils'], 2).' ₪'" icon="wallet" tone="amber" />
        <x-stat-card label="متأخرة" :value="$stats['overdue']" icon="minus" tone="red" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالرقم/العميل..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل العملاء</option>
            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">العميل</th>
                    <th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الاستحقاق</th>
                    <th class="px-4 py-3">الإجمالي</th><th class="px-4 py-3">المبلغ المتبقي</th>
                    <th class="px-4 py-3">الحالة</th><th class="px-4 py-3">الإجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invoices as $invoice)
                    @php
                        $eff = $invoice->effectiveStatus();
                        $isDraft = $invoice->isDraft();
                        $isCancelled = $invoice->isCancelled();
                        $isActive = ! $isDraft && ! $isCancelled;
                        $hasActivePayments = ($invoice->active_allocations_count ?? 0) > 0;
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $invoice->invoice_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800"><a href="{{ route('admin.invoices.show', $invoice) }}" class="hover:text-brand-600 hover:underline">{{ $invoice->customer->name }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $invoice->invoice_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3"><x-money :usd="$invoice->total_usd" :ils="$invoice->total_ils_at_issue" :rate="$invoice->exchange_rate" class="font-semibold text-slate-800" dir="ltr" /></td>
                        <td class="px-4 py-3"><x-money :usd="$invoice->remaining_usd" :rate="$invoice->exchange_rate" :class="(float) $invoice->remaining_usd > 0 ? 'font-semibold text-amber-700' : 'font-semibold text-emerald-700'" dir="ltr" /></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col items-start gap-1">
                                <x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge>
                                @if ($isActive)
                                    @php $remaining = (float) $invoice->remaining_usd; $total = (float) $invoice->total_usd; @endphp
                                    @if ($remaining <= 0)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>مدفوعة
                                        </span>
                                    @elseif ($remaining < $total)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>مدفوعة جزئياً
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>غير مدفوعة
                                        </span>
                                    @endif
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                {{-- View --}}
                                <a href="{{ route('admin.invoices.show', $invoice) }}"
                                   title="عرض الفاتورة" aria-label="عرض الفاتورة"
                                   class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-600">
                                    <x-icon name="eye" class="h-[18px] w-[18px]" />
                                </a>

                                {{-- Record payment: create a draft payment for this invoice's customer
                                     and open the payment page with the invoice prefilled (posted there,
                                     per correct accounting). Only for invoices that still accept a payment. --}}
                                @if ($canRecordPayment && $invoice->acceptsAllocation())
                                    <button type="button" wire:click="recordPayment({{ $invoice->id }})"
                                            wire:loading.attr="disabled" wire:target="recordPayment({{ $invoice->id }})"
                                            title="تسجيل دفعة عن الفاتورة" aria-label="تسجيل دفعة عن الفاتورة"
                                            class="rounded-md p-1.5 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <x-icon name="cash" class="h-[18px] w-[18px]" />
                                    </button>
                                @endif

                                {{-- Edit: a draft opens directly; an issued/sent invoice is reverted
                                     to a draft (journal reversed) first; blocked while it has payments. --}}
                                @if ($isDraft && $canEdit)
                                    <a href="{{ route('admin.invoices.show', $invoice) }}"
                                       title="تعديل الفاتورة" aria-label="تعديل الفاتورة"
                                       class="rounded-md p-1.5 text-slate-500 hover:bg-blue-50 hover:text-blue-600">
                                        <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                    </a>
                                @elseif ($isActive && $canEdit && ! $hasActivePayments)
                                    <button type="button" wire:click="editInvoice({{ $invoice->id }})"
                                            wire:confirm="سيُعاد إصدار الفاتورة كمسودة ويُعكس قيدها المحاسبي لتعديلها. متابعة؟"
                                            title="تعديل الفاتورة (إرجاع لمسودة وعكس القيد)" aria-label="تعديل الفاتورة"
                                            class="rounded-md p-1.5 text-slate-500 hover:bg-blue-50 hover:text-blue-600">
                                        <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                    </button>
                                @else
                                    <span title="{{ $hasActivePayments ? 'لا يمكن تعديل فاتورة لها دفعات — ألغِ الدفعات أولاً' : 'تعديل الفاتورة غير متاح' }}"
                                          aria-label="تعديل الفاتورة غير متاح"
                                          class="cursor-not-allowed rounded-md p-1.5 text-slate-300">
                                        <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                    </span>
                                @endif

                                {{-- Print --}}
                                @if ($canPrint)
                                    <a href="{{ route('admin.invoices.print', $invoice) }}" target="_blank" rel="noopener"
                                       title="طباعة الفاتورة" aria-label="طباعة الفاتورة"
                                       class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                                        <x-icon name="printer" class="h-[18px] w-[18px]" />
                                    </a>

                                    {{-- Download PDF --}}
                                    <a href="{{ route('admin.invoices.pdf', $invoice) }}"
                                       title="تنزيل PDF" aria-label="تنزيل الفاتورة PDF"
                                       class="rounded-md p-1.5 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600">
                                        <x-icon name="download" class="h-[18px] w-[18px]" />
                                    </a>
                                @endif

                                {{-- WhatsApp (issued only) --}}
                                @if ($isActive && $canSend && $whatsappEnabled)
                                    <button type="button" wire:click="openWhatsapp({{ $invoice->id }})"
                                            title="إرسال عبر واتساب" aria-label="إرسال الفاتورة عبر واتساب"
                                            class="rounded-md p-1.5 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700">
                                        <x-icon name="whatsapp" class="h-[18px] w-[18px]" />
                                    </button>
                                @endif

                                {{-- Delete draft / Cancel invoice --}}
                                @if ($isDraft && $canEdit)
                                    <button type="button" wire:click="openDelete({{ $invoice->id }})"
                                            title="حذف المسودة" aria-label="حذف مسودة الفاتورة"
                                            class="rounded-md p-1.5 text-red-500 hover:bg-red-50 hover:text-red-700">
                                        <x-icon name="trash" class="h-[18px] w-[18px]" />
                                    </button>
                                @elseif ($isActive && $canCancel)
                                    @if ($hasActivePayments)
                                        <span title="لا يمكن إلغاء هذه الفاتورة لوجود دفعات مرتبطة بها. يجب إلغاء/عكس الدفعات أولاً."
                                              aria-label="إلغاء الفاتورة غير متاح لوجود دفعات"
                                              class="cursor-not-allowed rounded-md p-1.5 text-slate-300">
                                            <x-icon name="ban" class="h-[18px] w-[18px]" />
                                        </span>
                                    @else
                                        <button type="button" wire:click="openCancel({{ $invoice->id }})"
                                                title="إلغاء الفاتورة" aria-label="إلغاء الفاتورة"
                                                class="rounded-md p-1.5 text-orange-600 hover:bg-orange-50 hover:text-orange-700">
                                            <x-icon name="ban" class="h-[18px] w-[18px]" />
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا فواتير.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>

    {{-- WhatsApp confirmation modal --}}
    <x-modal show="showWhatsapp" title="إرسال الفاتورة عبر واتساب" maxWidth="max-w-md">
        <div class="space-y-3 text-sm text-slate-700">
            <p>سيتم إرسال ملف PDF للفاتورة إلى العميل عبر واتساب. يرجى مراجعة التفاصيل:</p>
            <dl class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                <div class="flex justify-between px-3 py-2"><dt class="text-slate-500">العميل</dt><dd class="font-medium">{{ $waPreview['customer'] ?? '—' }}</dd></div>
                <div class="flex justify-between px-3 py-2"><dt class="text-slate-500">واتساب</dt><dd class="font-mono" dir="ltr">{{ $waPreview['phone'] ?? '—' }}</dd></div>
                <div class="flex justify-between px-3 py-2"><dt class="text-slate-500">رقم الفاتورة</dt><dd class="font-mono" dir="ltr">{{ $waPreview['number'] ?? '—' }}</dd></div>
                <div class="flex justify-between px-3 py-2"><dt class="text-slate-500">القيمة (USD)</dt><dd class="font-semibold" dir="ltr">${{ number_format((float) ($waPreview['total_usd'] ?? 0), 2) }}</dd></div>
                @if (($waPreview['total_ils'] ?? null) !== null)
                    <div class="flex justify-between px-3 py-2"><dt class="text-slate-500">بالشيكل (سعر الفاتورة)</dt><dd class="font-semibold" dir="ltr">{{ number_format((float) $waPreview['total_ils'], 2) }} ₪</dd></div>
                @endif
                <div class="flex justify-between px-3 py-2"><dt class="text-slate-500">الملف المرفق</dt><dd class="font-mono text-xs" dir="ltr">{{ $waPreview['filename'] ?? '—' }}</dd></div>
            </dl>
            @if (($waPreview['prior_sends'] ?? 0) > 0)
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">تنبيه: تم إرسال رسائل سابقة لهذه الفاتورة ({{ $waPreview['prior_sends'] }}). هذا إرسال إضافي.</p>
            @endif
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="$wire.showWhatsapp = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="button" wire:click="confirmWhatsapp" wire:loading.attr="disabled" wire:target="confirmWhatsapp"
                        class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                    <x-icon name="whatsapp" class="h-4 w-4" />
                    <span wire:loading.remove wire:target="confirmWhatsapp">إرسال الآن</span>
                    <span wire:loading wire:target="confirmWhatsapp">جارٍ الإرسال…</span>
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Delete-draft confirmation modal --}}
    <x-modal show="showDelete" title="حذف مسودة الفاتورة" maxWidth="max-w-md">
        <div class="space-y-4 text-sm text-slate-700">
            <p>سيتم حذف مسودة الفاتورة <span class="font-mono" dir="ltr">{{ $deleteNumber }}</span> نهائياً مع بنودها. هذا الإجراء متاح للمسودات فقط ولا يمكن التراجع عنه.</p>
            <div class="flex justify-end gap-2">
                <button type="button" @click="$wire.showDelete = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">تراجع</button>
                <button type="button" wire:click="confirmDelete" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">حذف نهائي</button>
            </div>
        </div>
    </x-modal>

    {{-- Cancel-invoice confirmation modal --}}
    <x-modal show="showCancel" title="إلغاء الفاتورة" maxWidth="max-w-md">
        <div class="space-y-4 text-sm text-slate-700">
            <p>سيتم إلغاء الفاتورة <span class="font-mono" dir="ltr">{{ $cancelNumber }}</span> وعكس قيودها المحاسبية (الذمم/الإيراد/الضريبة). تُحفظ الفاتورة في السجلات بحالة «ملغاة».</p>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">سبب الإلغاء</label>
                <textarea wire:model="cancelReason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="مثال: خطأ في الإصدار / طلب العميل"></textarea>
                @error('cancelReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" @click="$wire.showCancel = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">تراجع</button>
                <button type="button" wire:click="confirmCancel" class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">تأكيد الإلغاء</button>
            </div>
        </div>
    </x-modal>
</div>
