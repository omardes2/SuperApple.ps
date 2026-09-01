<div class="mx-auto max-w-[1600px] space-y-5">
    @php $eff = $invoice->effectiveStatus(); @endphp

    {{-- ── Header: identity + status + grouped actions ───────────────────── --}}
    <div class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.invoices') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50" title="رجوع" aria-label="رجوع للفواتير">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
            </a>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-slate-800" dir="ltr">{{ $invoice->invoice_number }}</h2>
                    <x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge>
                </div>
                <p class="mt-0.5 text-sm text-slate-500">{{ $invoice->customer?->name ?? '— بلا عميل' }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 lg:mr-auto">
            @can('print', $invoice)
                <a href="{{ route('admin.invoices.print', $invoice) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
                    <x-icon name="printer" class="h-4 w-4" /> طباعة
                </a>
            @endcan
            @can('cancel', $invoice)
                @unless ($invoice->isCancelled())
                    <button wire:click="openCancel" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">إلغاء الفاتورة</button>
                @endunless
            @endcan
            @if ($canRecordPayment)
                <button wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment"
                        class="rounded-lg border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">تسجيل دفعة</button>
            @endif
            @if ($invoice->isDraft())
                @can('issue', $invoice)
                    <button wire:click="issue" wire:loading.attr="disabled" wire:target="issue"
                            wire:confirm="سيتم إصدار الفاتورة وقفل بياناتها المالية نهائياً. متابعة؟"
                            class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">إصدار الفاتورة</button>
                @endcan
            @else
                @can('send', $invoice)
                    <button wire:click="send" wire:loading.attr="disabled" wire:target="send"
                            class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">إرسال</button>
                @endcan
            @endif
        </div>
    </div>

    @error('action') <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    @if ($invoice->isCancelled())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            فاتورة ملغاة — السبب: {{ $invoice->cancellation_reason }}
        </div>
    @endif

    {{-- ── Body: main (70-75%) + sticky sidebar (25-30%) ─────────────────── --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @if ($canEdit)
                <form wire:submit="save" class="space-y-5">
                    {{-- Invoice data --}}
                    <section class="rounded-xl border border-slate-200 bg-white p-6">
                        <h3 class="mb-4 font-semibold text-slate-800">بيانات الفاتورة</h3>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">العميل</label>
                                @if ($customer_id)
                                    <div class="flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm">
                                        <span class="font-medium text-slate-800">{{ $selectedCustomerName }}</span>
                                        <button type="button" wire:click="clearCustomer" class="text-xs text-red-500 hover:underline">تغيير</button>
                                    </div>
                                @else
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
                                @endif
                                @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ الفاتورة</label>
                                <input type="date" wire:model="invoice_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @error('invoice_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ الاستحقاق</label>
                                <input type="date" wire:model="due_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @error('due_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">سعر صرف الفاتورة</label>
                                <input type="number" step="0.000001" wire:model="exchange_rate" placeholder="مثال: 3.08" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <p class="mt-1 text-xs text-slate-400" dir="ltr">1 USD = ? ILS — يُثبت عند الإصدار</p>
                                @error('exchange_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </section>

                    {{-- Line items (the primary section) --}}
                    <section class="rounded-xl border border-slate-200 bg-white p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="font-semibold text-slate-800">بنود الفاتورة</h3>
                            <button type="button" wire:click="addLine" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-100">
                                <x-icon name="plus" class="h-4 w-4" /> إضافة بند
                            </button>
                        </div>
                        @include('livewire.admin.partials.line-editor', ['services' => $services])
                    </section>

                    {{-- Terms + notes --}}
                    <section class="rounded-xl border border-slate-200 bg-white p-6">
                        <h3 class="mb-4 font-semibold text-slate-800">الشروط والملاحظات</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">شروط الفاتورة / التذييل</label>
                                <textarea wire:model="terms" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات داخلية</label>
                                <textarea wire:model="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                            </div>
                        </div>
                    </section>

                    {{-- Footer save --}}
                    <div class="flex justify-end">
                        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                                class="rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">حفظ المسودة</button>
                    </div>
                </form>
            @else
                {{-- Read-only view for issued/sent/cancelled invoices --}}
                <section class="rounded-xl border border-slate-200 bg-white p-6">
                    <h3 class="mb-4 font-semibold text-slate-800">بيانات الفاتورة</h3>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div class="flex justify-between sm:block"><dt class="text-slate-500">العميل</dt><dd class="font-medium text-slate-800">{{ $invoice->customer->name }}</dd></div>
                        <div class="flex justify-between sm:block"><dt class="text-slate-500">تاريخ الفاتورة</dt><dd class="text-slate-800" dir="ltr">{{ $invoice->invoice_date->format('Y-m-d') }}</dd></div>
                        <div class="flex justify-between sm:block"><dt class="text-slate-500">تاريخ الاستحقاق</dt><dd class="text-slate-800" dir="ltr">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</dd></div>
                        <div class="flex justify-between sm:block"><dt class="text-slate-500">سعر الصرف</dt><dd class="text-slate-800" dir="ltr">{{ $invoice->exchange_rate ?? '—' }}</dd></div>
                    </dl>
                </section>

                <div>
                    <h3 class="mb-3 font-semibold text-slate-800">بنود الفاتورة</h3>
                    @include('livewire.admin.partials.items-readonly', ['document' => $invoice])
                </div>

                @if ($invoice->terms || $invoice->notes)
                    <section class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
                        <h3 class="mb-3 font-semibold text-slate-800">الشروط والملاحظات</h3>
                        @if ($invoice->terms)<p>{{ $invoice->terms }}</p>@endif
                        @if ($invoice->notes)<p class="mt-2 text-slate-500">{{ $invoice->notes }}</p>@endif
                    </section>
                @endif
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-5 lg:sticky lg:top-20 lg:self-start">
            @include('livewire.admin.partials.totals-card', [
                'totals' => $canEdit ? $preview : [
                    'subtotal_usd' => $invoice->subtotal_usd, 'discount_usd' => $invoice->discount_usd,
                    'tax_usd' => $invoice->tax_usd, 'total_usd' => $invoice->total_usd,
                ],
                'invoice' => $invoice,
            ])

            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">معلومات الفاتورة</h3>
                <dl class="space-y-2 text-slate-600">
                    <div class="flex justify-between"><dt>الحالة</dt><dd><x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge></dd></div>
                    <div class="flex justify-between"><dt>تاريخ الإنشاء</dt><dd dir="ltr">{{ $invoice->created_at->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt>الاستحقاق</dt><dd dir="ltr">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt>سعر الصرف</dt><dd dir="ltr">{{ $invoice->exchange_rate ?? '—' }}</dd></div>
                </dl>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">سجل الفاتورة</h3>
                <ul class="space-y-2 text-slate-600">
                    <li class="flex justify-between"><span>● أُنشئت</span><span dir="ltr">{{ $invoice->created_at->format('Y-m-d') }}</span></li>
                    @if ($invoice->issued_at)<li class="flex justify-between"><span>● صدرت</span><span dir="ltr">{{ $invoice->issued_at->format('Y-m-d H:i') }}</span></li>@endif
                    @if ($invoice->sent_at)<li class="flex justify-between"><span>● أُرسلت</span><span dir="ltr">{{ $invoice->sent_at->format('Y-m-d') }}</span></li>@endif
                    @if ($invoice->cancelled_at)<li class="flex justify-between text-red-600"><span>● أُلغيت</span><span dir="ltr">{{ $invoice->cancelled_at->format('Y-m-d') }}</span></li>@endif
                </ul>
            </div>

            @if ($canPayments && ! $invoice->isDraft())
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                    <h3 class="mb-3 font-semibold text-slate-800">الدفعات والتحصيل</h3>
                    <dl class="space-y-2 text-slate-600">
                        <div class="flex justify-between"><dt>الإجمالي</dt><dd class="text-left"><x-money :usd="$invoice->total_usd" :ils="$invoice->total_ils_at_issue" :rate="$invoice->exchange_rate" class="font-semibold text-slate-800" dir="ltr" /></dd></div>
                        <div class="flex justify-between"><dt>المدفوع</dt><dd class="text-left"><x-money :usd="$invoice->paid_usd_equivalent" :rate="$invoice->exchange_rate" class="text-emerald-700" dir="ltr" /></dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-2"><dt>المتبقي</dt><dd class="text-left"><x-money :usd="$invoice->remaining_usd" :rate="$invoice->exchange_rate" class="font-bold text-slate-900" dir="ltr" /></dd></div>
                    </dl>
                    @if ($allocations->isNotEmpty())
                        <ul class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 text-xs">
                            @foreach ($allocations as $alloc)
                                <li class="flex items-center justify-between {{ $alloc->status === 'reversed' ? 'text-slate-400 line-through' : 'text-slate-600' }}">
                                    <a href="{{ route('admin.payments.show', $alloc->payment) }}" class="font-mono hover:text-brand-600 hover:underline" dir="ltr">{{ $alloc->payment->payment_number }}</a>
                                    <span dir="ltr">${{ number_format((float) $alloc->allocated_usd, 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($invoice->subscription)
        {{-- Subscriptions module retired: legacy link kept as plain text only. --}}
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            فاتورة مرتبطة باشتراك قديم (سجل أرشيفي) —
            <span class="font-semibold">{{ $invoice->subscription->name }} ({{ $invoice->subscription->subscription_number }})</span>
        </div>
    @endif

    @if ($canWhatsapp)
        <div class="rounded-xl border border-slate-200 bg-white" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex w-full items-center justify-between px-5 py-4 text-right">
                <h3 class="font-semibold text-slate-800">سجل رسائل واتساب</h3>
                <svg class="h-5 w-5 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div x-show="open" x-cloak class="border-t border-slate-100 px-5 pb-5 pt-3">
                <table class="min-w-full text-sm">
                    <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">التاريخ</th><th class="py-2">النص</th><th class="py-2">الحالة</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($whatsappMessages as $m)
                            <tr>
                                <td class="py-2 text-slate-400" dir="ltr">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="py-2 text-slate-600">{{ \Illuminate\Support\Str::limit($m->message_body, 60) }}</td>
                                <td class="py-2"><x-badge :class="$m->status->badgeClass()">{{ $m->status->label() }}</x-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-slate-400">لا رسائل.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <x-modal show="showCancel" title="إلغاء الفاتورة">
        <form wire:submit="confirmCancel" class="space-y-4">
            <p class="text-sm text-slate-600">لا تُحذف الفاتورة الصادرة؛ تُلغى مع حفظ رقمها وسببها.</p>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">سبب الإلغاء</label>
                <textarea wire:model="cancelReason" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('cancelReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showCancel = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">تراجع</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="confirmCancel" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">تأكيد الإلغاء</button>
            </div>
        </form>
    </x-modal>
</div>
