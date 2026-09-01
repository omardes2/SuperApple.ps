<div>
    <x-page-header title="الفواتير" subtitle="الحساب الرسمي للعميل بالدولار الأمريكي (USD)">
        <x-slot:actions>
            @if ($canExport)
                <button wire:click="export" wire:loading.attr="disabled" wire:target="export"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-60">
                    <x-icon name="download" class="h-4 w-4" /> تصدير Excel
                </button>
            @endif
            @can('create', \App\Models\Invoice::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ فاتورة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>@endif

    {{-- KPI cards: this-month volume, outstanding, then the payment-state counts --}}
    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat-card label="فواتير هذا الشهر" :value="$stats['issued_month']" :hint="'$'.number_format((float) $stats['invoiced_month'], 2).' · ≈ '.number_format((float) $stats['invoiced_month_ils'], 2).' ₪'" icon="invoice" tone="brand" />
        <x-stat-card label="المستحق (Outstanding)" :value="'$'.number_format((float) $stats['outstanding'], 2)" :hint="'≈ '.number_format((float) $stats['outstanding_ils'], 2).' ₪'" icon="wallet" tone="amber" />
        <x-stat-card label="غير مدفوعة" :value="$tabCounts['unpaid']" icon="doc" tone="slate" />
        <x-stat-card label="مدفوعة جزئياً" :value="$tabCounts['partial']" icon="repeat" tone="amber" />
        <x-stat-card label="متأخرة" :value="$stats['overdue']" icon="minus" tone="red" />
    </div>

    {{-- Quick status tabs (derived filters over existing columns — no new status) --}}
    @php
        $tabs = [
            'all' => 'الكل', 'draft' => 'مسودة', 'unpaid' => 'غير مدفوعة', 'partial' => 'مدفوعة جزئياً',
            'paid' => 'مدفوعة', 'overdue' => 'متأخرة', 'cancelled' => 'ملغاة',
        ];
    @endphp
    <div class="mb-4 flex flex-nowrap gap-1.5 overflow-x-auto pb-1">
        @foreach ($tabs as $key => $label)
            @php $active = $tab === $key; @endphp
            <button type="button" wire:click="selectTab('{{ $key }}')"
                    class="inline-flex flex-none items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition
                        {{ $active ? 'bg-brand-600 text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}">
                <span>{{ $label }}</span>
                <span class="rounded-full px-1.5 text-xs {{ $active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500' }}">{{ $tabCounts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالرقم / العميل / رقم العميل / واتساب..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="customer" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل العملاء</option>
            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات (مستند)</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="perPage" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @foreach ([15, 25, 50] as $n)<option value="{{ $n }}">{{ $n }} / صفحة</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-2.5">الرقم</th>
                    <th class="px-4 py-2.5 hidden md:table-cell">التاريخ</th>
                    <th class="px-4 py-2.5">العميل</th>
                    <th class="px-4 py-2.5">الإجمالي</th>
                    <th class="px-4 py-2.5 hidden lg:table-cell">المدفوع</th>
                    <th class="px-4 py-2.5">المتبقي</th>
                    <th class="px-4 py-2.5 hidden md:table-cell">الاستحقاق</th>
                    <th class="px-4 py-2.5">الحالة</th>
                    <th class="px-4 py-2.5 text-center">الإجراءات</th>
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
                        $remaining = (float) $invoice->remaining_usd;
                        $paid = (float) $invoice->paid_usd_equivalent;
                        // Deletable only when tied to NO payment at all (any
                        // allocation row, active or reversed, or a paid amount).
                        $hasAnyPayments = ($invoice->allocations_count ?? 0) > 0 || $paid > 0;
                        $deletePermitted = $isDraft ? $canEdit : $canCancel;
                        $isOverdue = $eff === \App\Enums\InvoiceStatus::Overdue;
                        $overdueDays = ($isOverdue && $invoice->due_date) ? (int) $invoice->due_date->diffInDays(now()) : 0;
                        // ILS at the invoice's OWN stored rate (never a current rate);
                        // null when a draft has no rate yet → USD shown alone.
                        $rate = $invoice->exchange_rate;
                        $hasRate = $rate !== null && (float) $rate > 0;
                        $totalIls = $invoice->total_ils_at_issue ?: ($hasRate ? \App\Support\Money::convertUsdToIls($invoice->total_usd, $rate) : null);
                        $paidIls = $hasRate ? \App\Support\Money::convertUsdToIls($invoice->paid_usd_equivalent, $rate) : null;
                        $remIls = $hasRate ? \App\Support\Money::convertUsdToIls($invoice->remaining_usd, $rate) : null;
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 font-mono text-slate-500 whitespace-nowrap" dir="ltr">
                            <a href="{{ route('admin.invoices.show', $invoice) }}" class="hover:text-brand-600 hover:underline">{{ $invoice->invoice_number }}</a>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600 whitespace-nowrap hidden md:table-cell" dir="ltr">{{ $invoice->invoice_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-2.5 font-medium text-slate-800"><a href="{{ route('admin.invoices.show', $invoice) }}" class="hover:text-brand-600 hover:underline">{{ $invoice->customer?->name ?? '— بلا عميل' }}</a></td>
                        <td class="px-4 py-2.5"><x-amount :ils="$totalIls" :usd="$invoice->total_usd" :usd-approx="false" class="font-semibold text-slate-800" /></td>
                        <td class="px-4 py-2.5 hidden lg:table-cell"><x-amount :ils="$paidIls" :usd="$invoice->paid_usd_equivalent" :usd-approx="false" :class="$paid > 0 ? 'font-medium text-emerald-700' : 'text-slate-400'" /></td>
                        <td class="px-4 py-2.5"><x-amount :ils="$remIls" :usd="$invoice->remaining_usd" :usd-approx="false" :class="$remaining > 0 ? 'font-semibold text-amber-700' : 'font-semibold text-emerald-600'" /></td>
                        <td class="px-4 py-2.5 whitespace-nowrap hidden md:table-cell" dir="ltr">
                            @if ($invoice->due_date)
                                <span class="text-slate-600">{{ $invoice->due_date->format('Y-m-d') }}</span>
                                @if ($isOverdue)<span class="mt-0.5 block text-[11px] font-medium text-red-600">متأخرة {{ $overdueDays }} يوم</span>@endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($isDraft)
                                    <x-badge :class="\App\Enums\InvoiceStatus::Draft->badgeClass()">مسودة</x-badge>
                                @elseif ($isCancelled)
                                    <x-badge :class="\App\Enums\InvoiceStatus::Cancelled->badgeClass()">ملغاة</x-badge>
                                @else
                                    {{-- Document status --}}
                                    <span class="inline-flex items-center rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700">{{ $invoice->sent_at ? 'مُرسلة' : 'صادرة' }}</span>
                                    <span class="text-slate-300">·</span>
                                    {{-- Payment status (derived, folds overdue) --}}
                                    @if ($isOverdue)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>متأخرة</span>
                                    @elseif ($remaining <= 0)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>مدفوعة</span>
                                    @elseif ($paid > 0)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>جزئياً</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600"><span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>غير مدفوعة</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-center gap-1">
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

                                {{-- Cancel invoice (issued, unpaid) --}}
                                @if ($isActive && $canCancel)
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

                                {{-- Delete invoice (all statuses; disabled when tied to payments) --}}
                                @if ($deletePermitted)
                                    @if ($hasAnyPayments)
                                        <span title="لا يمكن حذف فاتورة مدفوعة أو مرتبطة بدفعات."
                                              aria-label="حذف الفاتورة غير متاح لوجود دفعات"
                                              class="cursor-not-allowed rounded-md p-1.5 text-slate-300">
                                            <x-icon name="trash" class="h-[18px] w-[18px]" />
                                        </span>
                                    @else
                                        <button type="button" wire:click="openDelete({{ $invoice->id }})"
                                                title="{{ $isDraft ? 'حذف المسودة' : 'حذف الفاتورة (عكس القيد ثم الحذف)' }}"
                                                aria-label="حذف الفاتورة"
                                                class="rounded-md p-1.5 text-red-500 hover:bg-red-50 hover:text-red-700">
                                            <x-icon name="trash" class="h-[18px] w-[18px]" />
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">لا فواتير.</td></tr>
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

    {{-- Delete confirmation modal --}}
    <x-modal show="showDelete" :title="$deleteIsDraft ? 'حذف مسودة الفاتورة' : 'حذف الفاتورة'" maxWidth="max-w-md">
        <div class="space-y-4 text-sm text-slate-700">
            @if ($deleteIsDraft)
                <p>سيتم حذف مسودة الفاتورة <span class="font-mono" dir="ltr">{{ $deleteNumber }}</span> نهائياً مع بنودها. لا يمكن التراجع عن هذا الإجراء.</p>
            @else
                <p>سيتم <b>عكس القيد المحاسبي</b> للفاتورة <span class="font-mono" dir="ltr">{{ $deleteNumber }}</span> ثم حذفها من السجل. تبقى القيود الأصلية والعكسية محفوظة في دفتر الأستاذ (لا يُحذف أي قيد)، وتعود الذمم والإيراد إلى الصفر. لا يمكن التراجع عن هذا الإجراء.</p>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" @click="$wire.showDelete = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">تراجع</button>
                <button type="button" wire:click="confirmDelete" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">{{ $deleteIsDraft ? 'حذف نهائي' : 'حذف الفاتورة وعكس قيدها' }}</button>
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
