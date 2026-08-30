<div>
    @php $eff = $invoice->effectiveStatus(); @endphp
    <div class="mb-5 flex flex-wrap items-center gap-4">
        <a href="{{ route('admin.invoices') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-800" dir="ltr">{{ $invoice->invoice_number }}</h2>
            <p class="text-sm text-slate-500">{{ $invoice->customer->name }}</p>
        </div>
        <div class="mr-auto"><x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge></div>
    </div>

    @error('action') <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    <div class="mb-5 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-4">
        @if ($invoice->isDraft())
            @can('issue', $invoice)<button wire:click="issue" wire:confirm="سيتم إصدار الفاتورة وقفل بياناتها المالية نهائياً. متابعة؟" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">إصدار الفاتورة</button>@endcan
        @else
            @can('send', $invoice)<button wire:click="send" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إرسال</button>@endcan
        @endif
        @can('cancel', $invoice)
            @unless ($invoice->isCancelled())
                <button wire:click="openCancel" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50">إلغاء الفاتورة</button>
            @endunless
        @endcan
        @if ($canRecordPayment)
            <button wire:click="recordPayment" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">تسجيل دفعة</button>
        @endif
        @can('print', $invoice)
            <a href="{{ route('admin.invoices.print', $invoice) }}" target="_blank" class="mr-auto rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</a>
        @endcan
    </div>

    @if ($invoice->isCancelled())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            فاتورة ملغاة — السبب: {{ $invoice->cancellation_reason }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @if ($canEdit)
                <form wire:submit="save" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">العميل</label>
                            <select wire:model="customer_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                            @error('customer_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">المشروع</label>
                            <select wire:model="project_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">— بدون —</option>
                                @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ الفاتورة</label>
                            <input type="date" wire:model="invoice_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ الاستحقاق</label>
                            <input type="date" wire:model="due_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('due_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700">سعر الصرف (1 USD = ? ILS) — يُقفل عند الإصدار</label>
                            <div class="flex gap-2">
                                <input type="number" step="0.000001" wire:model="exchange_rate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <button type="button" wire:click="suggestRate" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-600 hover:bg-slate-50">اقتراح آخر سعر</button>
                            </div>
                            @error('exchange_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @include('livewire.admin.partials.line-editor', ['services' => $services])

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">الشروط / التذييل</label>
                        <textarea wire:model="terms" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end"><button type="submit" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ المسودة</button></div>
                </form>
            @else
                @include('livewire.admin.partials.items-readonly', ['document' => $invoice])
                @if ($invoice->terms)
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">{{ $invoice->terms }}</div>
                @endif
            @endif
        </div>

        <div class="space-y-5">
            @include('livewire.admin.partials.totals-card', [
                'totals' => $canEdit ? $preview : [
                    'subtotal_usd' => $invoice->subtotal_usd, 'discount_usd' => $invoice->discount_usd,
                    'tax_usd' => $invoice->tax_usd, 'total_usd' => $invoice->total_usd,
                ],
                'invoice' => $invoice,
            ])

            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">التسلسل الزمني</h3>
                <ul class="space-y-2 text-slate-600">
                    <li class="flex justify-between"><span>أُنشئت</span><span dir="ltr">{{ $invoice->created_at->format('Y-m-d') }}</span></li>
                    @if ($invoice->issued_at)<li class="flex justify-between"><span>صدرت</span><span dir="ltr">{{ $invoice->issued_at->format('Y-m-d H:i') }}</span></li>@endif
                    @if ($invoice->sent_at)<li class="flex justify-between"><span>أُرسلت</span><span dir="ltr">{{ $invoice->sent_at->format('Y-m-d') }}</span></li>@endif
                    @if ($invoice->cancelled_at)<li class="flex justify-between"><span>أُلغيت</span><span dir="ltr">{{ $invoice->cancelled_at->format('Y-m-d') }}</span></li>@endif
                    @if ($invoice->quotation)<li class="flex justify-between"><span>من عرض</span><span dir="ltr">{{ $invoice->quotation->quotation_number }}</span></li>@endif
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
        <div class="mt-5 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800">
            فاتورة اشتراك متكررة —
            <a href="{{ route('admin.subscriptions.show', $invoice->subscription) }}" class="font-semibold underline">{{ $invoice->subscription->name }} ({{ $invoice->subscription->subscription_number }})</a>
        </div>
    @endif

    @if ($canWhatsapp)
        <div class="mt-5 rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">سجل رسائل واتساب</h3>
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
                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">تأكيد الإلغاء</button>
            </div>
        </form>
    </x-modal>
</div>
