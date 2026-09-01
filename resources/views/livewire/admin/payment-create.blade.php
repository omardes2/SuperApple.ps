<div>
    <x-page-header title="تسجيل دفعة" subtitle="القيمة الرسمية بالدولار الأمريكي (USD)">
        <x-slot:actions>
            <a href="{{ route('admin.payments') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">رجوع</a>
        </x-slot:actions>
    </x-page-header>

    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            {{-- ── Customer ─────────────────────────────────────────────── --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
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
                @error('customer_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- ── Customer receivables (top): pay opening balance or an invoice ── --}}
            @if ($customer_id)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-slate-800">ذمم العميل</h3>
                        <div class="text-sm">
                            <span class="text-slate-500">إجمالي المستحق:</span>
                            <span class="font-bold text-amber-700" dir="ltr">${{ number_format((float) $outstandingUsd, 2) }}</span>
                            <span class="text-xs text-slate-400" dir="ltr">≈ {{ number_format((float) $outstandingIls, 2) }} ₪</span>
                            @if ((float) $creditUsd > 0)
                                <span class="ms-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700" dir="ltr">رصيد دائن ${{ number_format((float) $creditUsd, 2) }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($openInvoices->isEmpty() && ! $openOpeningBalance)
                        <p class="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500">لا توجد ذمم مفتوحة لهذا العميل. يمكنك تسجيل دفعة تُحفظ كرصيد دائن.</p>
                    @else
                        <div class="space-y-2">
                            @if ($openOpeningBalance)
                                @php $obSelected = in_array($openOpeningBalance->id, $selectedObIds, true); @endphp
                                <div class="flex items-center justify-between rounded-lg border {{ $obSelected ? 'border-brand-300 bg-brand-50' : 'border-amber-200 bg-amber-50/40' }} px-3 py-2.5">
                                    <div class="text-sm">
                                        <span class="font-medium text-amber-800">رصيد افتتاحي</span>
                                        <span class="mr-2 text-slate-500" dir="ltr">متبقٍ ${{ number_format((float) $openOpeningBalance->remaining_usd, 2) }}
                                            @if ($openOpeningBalance->exchange_rate)<span class="text-xs text-slate-400">≈ {{ number_format((float) \App\Support\Money::convertUsdToIls($openOpeningBalance->remaining_usd, $openOpeningBalance->exchange_rate), 2) }} ₪</span>@endif
                                        </span>
                                    </div>
                                    <button type="button" wire:click="payOpeningBalance({{ $openOpeningBalance->id }})"
                                            class="rounded-lg px-3 py-1.5 text-xs font-medium {{ $obSelected ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-amber-300 text-amber-700 hover:bg-amber-50' }}">
                                        {{ $obSelected ? '✓ محدّد' : 'دفع هذا' }}
                                    </button>
                                </div>
                            @endif

                            @foreach ($openInvoices as $inv)
                                @php $sel = in_array($inv->id, $selectedInvoiceIds, true); $od = $inv->isOverdue(); @endphp
                                <div class="flex items-center justify-between rounded-lg border {{ $sel ? 'border-brand-300 bg-brand-50' : 'border-slate-200' }} px-3 py-2.5">
                                    <div class="text-sm">
                                        <a href="{{ route('admin.invoices.show', $inv) }}" class="font-mono font-medium text-slate-800 hover:text-brand-600 hover:underline" dir="ltr">{{ $inv->invoice_number }}</a>
                                        <span class="mr-2 text-slate-500" dir="ltr">متبقٍ ${{ number_format((float) $inv->remaining_usd, 2) }}
                                            @if ($inv->exchange_rate)<span class="text-xs text-slate-400">≈ {{ number_format((float) \App\Support\Money::convertUsdToIls($inv->remaining_usd, $inv->exchange_rate), 2) }} ₪</span>@endif
                                        </span>
                                        @if ($od)<span class="mr-1 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700">متأخرة</span>@endif
                                    </div>
                                    <button type="button" wire:click="payInvoice({{ $inv->id }})"
                                            class="rounded-lg px-3 py-1.5 text-xs font-medium {{ $sel ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-slate-300 text-slate-600 hover:bg-slate-50' }}">
                                        {{ $sel ? '✓ محدّد' : 'دفع' }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-slate-400">اختر رصيد بداية المدة أو فاتورة/فواتير للدفع عنها؛ يُملأ المبلغ تلقائياً ويمكنك تعديله (دفعة جزئية أو زائدة).</p>
                    @endif
                </div>
            @endif

            {{-- ── Payment fields ───────────────────────────────────────── --}}
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-4 font-semibold text-slate-800">بيانات الدفعة</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">تاريخ الدفعة</label>
                        <input type="date" wire:model.live="payment_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('payment_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">العملة</label>
                        <select wire:model.live="payment_currency" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($currencyOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">المبلغ المستلم</label>
                        <input type="number" step="0.01" min="0" wire:model.live="payment_amount" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('payment_amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @if ($payment_currency === 'ILS')
                        <div>
                            <label class="mb-1 block text-sm text-slate-600">سعر الصرف</label>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">يُحتسب حسب سعر صرف الفاتورة/الرصيد المُخصَّص — المبلغ بالشيكل كما هو.</div>
                        </div>
                    @else
                        <div>
                            <label class="mb-1 block text-sm text-slate-600">سعر صرف الدفعة (1 USD = ? ILS)</label>
                            <input type="number" step="0.000001" min="0" wire:model.live="exchange_rate" placeholder="مثال: 3.05" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('exchange_rate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">طريقة الدفع</label>
                        <select wire:model.live="payment_method" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($methodOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">إيداع في <span class="text-red-500">*</span></label>
                        <select wire:model.live="account_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— اختر الصندوق/الحساب —</option>
                            @foreach ($depositAccounts as $acc)<option value="{{ $acc->id }}">{{ $acc->name }} ({{ $acc->currency }})</option>@endforeach
                        </select>
                        @if ($depositAccounts->isEmpty())
                            <p class="mt-1 text-xs text-amber-600">لا يوجد حساب نقدي/بنكي نشط بعملة {{ $payment_currency }}.</p>
                        @endif
                        @error('account_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">رقم المرجع (اختياري)</label>
                        <input type="text" wire:model.live="reference_number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-slate-600">ملاحظات</label>
                        <textarea wire:model.live="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Sidebar: live summary + actions ──────────────────────────── --}}
        <div class="space-y-5 lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">ملخص</h3>
                <div class="mb-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 p-3 text-center text-sm">
                    <div><p class="text-xs text-slate-500">قيمة الدفعة USD</p><p class="font-bold text-slate-800" dir="ltr">${{ number_format((float) $usdPreview, 2) }}</p></div>
                    <div><p class="text-xs text-slate-500">المخصّص</p><p class="font-bold text-slate-800" dir="ltr">${{ number_format((float) $allocatedTotal, 2) }}</p></div>
                    <div><p class="text-xs text-slate-500">غير مخصّص</p><p class="font-bold {{ (float) $unallocatedPreview < 0 ? 'text-red-600' : 'text-emerald-700' }}" dir="ltr">${{ number_format((float) $unallocatedPreview, 2) }}</p></div>
                </div>

                @if ($paymentSummary['state'] !== 'none')
                    @php $ps = $paymentSummary; @endphp
                    <div class="rounded-lg border border-slate-200 p-4 text-sm">
                        <p class="mb-2 font-semibold text-slate-700">نتيجة الدفعة</p>
                        <dl class="space-y-1.5">
                            <div class="flex justify-between">
                                <dt class="text-slate-500">المبلغ المستلم</dt>
                                <dd class="font-semibold text-slate-800" dir="ltr">{{ number_format((float) $ps['received'], 2) }} {{ $ps['symbol'] }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">{{ $ps['state'] === 'exact' ? 'سيتم تسديد الفاتورة بالكامل' : 'المخصّص للفواتير' }}</dt>
                                <dd class="font-semibold text-slate-800" dir="ltr">
                                    ${{ number_format((float) $ps['allocated_usd'], 2) }}@if ($ps['currency'] === 'ILS' && $ps['allocated_ils'] !== null) <span class="text-xs font-normal text-slate-400">≈ {{ number_format((float) $ps['allocated_ils'], 2) }} ₪</span>@endif
                                </dd>
                            </div>
                            @if ($ps['state'] === 'partial')
                                <div class="flex justify-between border-t border-slate-100 pt-1.5">
                                    <dt class="text-slate-500">المتبقي على الفاتورة بعد الدفع</dt>
                                    <dd class="font-semibold text-amber-700" dir="ltr">${{ number_format((float) $ps['remaining_after_usd'], 2) }}</dd>
                                </div>
                            @elseif ($ps['state'] === 'exact')
                                <div class="flex justify-between border-t border-slate-100 pt-1.5">
                                    <dt class="text-slate-500">الرصيد الزائد</dt>
                                    <dd class="font-semibold text-emerald-600" dir="ltr">0.00 {{ $ps['symbol'] }}</dd>
                                </div>
                            @elseif ($ps['state'] === 'overpayment')
                                <div class="flex justify-between border-t border-slate-100 pt-1.5">
                                    <dt class="text-slate-500">الرصيد الزائد</dt>
                                    <dd class="font-semibold text-emerald-700" dir="ltr">
                                        @if ($ps['surplus_original_ils'] !== null){{ number_format((float) $ps['surplus_original_ils'], 2) }} ₪ <span class="text-xs font-normal text-slate-400">≈ ${{ number_format((float) $ps['surplus_usd'], 2) }}</span>@else ${{ number_format((float) $ps['surplus_usd'], 2) }}@endif
                                    </dd>
                                </div>
                                <p class="mt-1 rounded-md bg-emerald-50 px-3 py-2 text-xs text-emerald-700">سيُحفظ المبلغ الزائد كرصيد دائن غير مخصص للعميل ويمكن استخدامه لاحقاً.</p>
                            @endif
                        </dl>
                    </div>
                @endif

                <div class="mt-4 space-y-2">
                    <button wire:click="post" wire:loading.attr="disabled" wire:target="post"
                            class="w-full rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                        <span wire:loading.remove wire:target="post">ترحيل الدفعة</span>
                        <span wire:loading wire:target="post">جارٍ الترحيل…</span>
                    </button>
                    <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="w-full rounded-lg border border-slate-300 px-5 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-60">حفظ كمسودة</button>
                </div>
                <p class="mt-2 text-center text-xs text-slate-400">لا يتم إنشاء أي سجل حتى تضغط ترحيل أو حفظ.</p>
            </div>
        </div>
    </div>
</div>
