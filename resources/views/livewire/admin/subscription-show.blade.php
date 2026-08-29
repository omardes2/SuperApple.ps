<div>
    <x-page-header :title="$sub->name" :subtitle="$sub->subscription_number.' — '.$sub->customer?->name">
        <x-slot:actions>
            <a href="{{ route('admin.subscriptions') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">رجوع</a>
            @if ($canActivate && in_array($sub->status->value, ['draft','paused']))<button wire:click="activate" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">تفعيل</button>@endif
            @if ($canPause && $sub->status->value === 'active')<button wire:click="pause" wire:confirm="إيقاف الاشتراك مؤقتاً؟" class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white">إيقاف مؤقت</button>@endif
            @if ($canResume && $sub->status->value === 'paused')<button wire:click="$set('showResume', true)" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">استئناف</button>@endif
            @if ($canBill && $sub->status->value === 'active')<button wire:click="billNow" wire:confirm="توليد فاتورة الآن للفترة المستحقة؟" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white">فوترة الآن</button>@endif
            @if ($canCancel && $sub->status->value !== 'cancelled')<button wire:click="$set('showCancel', true)" class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600">إلغاء</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-stat-card label="الحالة" :value="$sub->status->label()" icon="dot" tone="slate" />
        <x-stat-card label="الدورة" :value="$sub->billing_cycle->label().($sub->billing_interval > 1 ? ' ×'.$sub->billing_interval : '')" icon="repeat" tone="brand" />
        <x-stat-card label="الفوترة القادمة" :value="$sub->next_billing_date?->toDateString() ?? '—'" icon="calendar" tone="violet" />
        @if ($canPrices)<x-stat-card label="القيمة لكل فترة" :value="number_format((float) $sub->total_usd, 2).' $'" :hint="$mrr ? 'MRR ≈ '.number_format((float)$mrr,2).' $' : null" icon="cash" tone="emerald" />@endif
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">البنود</h3>
                <table class="min-w-full text-sm">
                    <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">البند</th><th class="py-2">الكمية</th>@if ($canPrices)<th class="py-2">السعر</th><th class="py-2">الضريبة</th>@endif</tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($sub->items as $item)
                            <tr><td class="py-2 text-slate-800">{{ $item->item_name }}</td><td class="py-2 text-slate-600" dir="ltr">{{ number_format((float) $item->quantity, 2) }}</td>@if ($canPrices)<td class="py-2 text-slate-600" dir="ltr">{{ number_format((float) $item->unit_price_usd, 2) }} $</td><td class="py-2 text-slate-500" dir="ltr">{{ number_format((float) $item->tax_rate, 2) }}%</td>@endif</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">سجل الفوترة</h3>
                <table class="min-w-full text-sm">
                    <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الفترة</th><th class="py-2">تاريخ الفوترة</th><th class="py-2">الفاتورة</th><th class="py-2">الحالة</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($sub->billings as $b)
                            <tr>
                                <td class="py-2 text-slate-600" dir="ltr">{{ $b->period_start?->toDateString() }} → {{ $b->period_end?->toDateString() }}</td>
                                <td class="py-2 text-slate-500" dir="ltr">{{ $b->billing_date?->toDateString() }}</td>
                                <td class="py-2">@if ($b->invoice)<a href="{{ route('admin.invoices.show', $b->invoice) }}" class="font-mono text-brand-600 hover:underline" dir="ltr">{{ $b->invoice->invoice_number }}</a>@else<span class="text-slate-400">—</span>@endif</td>
                                <td class="py-2"><span class="text-xs text-slate-600">{{ $b->status }}</span>@if ($b->error_message)<span class="block text-xs text-red-500">{{ $b->error_message }}</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-slate-400">لا فواتير بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">معلومات</h3>
                <dl class="space-y-2 text-slate-600">
                    <div class="flex justify-between"><dt>البدء</dt><dd dir="ltr">{{ $sub->start_date?->toDateString() }}</dd></div>
                    <div class="flex justify-between"><dt>الانتهاء</dt><dd dir="ltr">{{ $sub->end_date?->toDateString() ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt>توليد تلقائي</dt><dd>{{ $sub->auto_generate_invoice ? 'نعم' : 'لا' }}</dd></div>
                    <div class="flex justify-between"><dt>إصدار تلقائي</dt><dd>{{ $sub->auto_issue_invoice ? 'نعم' : 'لا' }}</dd></div>
                    <div class="flex justify-between"><dt>المشروع</dt><dd>{{ $sub->project?->name ?? '—' }}</dd></div>
                </dl>
                @if ($sub->terms)<p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">{{ $sub->terms }}</p>@endif
                @if ($sub->status->value === 'cancelled')<p class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-600">أُلغي: {{ $sub->cancellation_reason }}</p>@endif
            </div>
        </div>
    </div>

    <x-modal show="showCancel" title="إلغاء الاشتراك">
        <p class="mb-3 text-sm text-slate-600">لن تُحذف الفواتير السابقة. سيتوقف توليد أي فواتير مستقبلية.</p>
        <textarea wire:model="cancelReason" rows="3" placeholder="سبب الإلغاء" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>@error('cancelReason')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showCancel=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="cancel" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">تأكيد الإلغاء</button></div>
    </x-modal>

    <x-modal show="showResume" title="استئناف الاشتراك">
        <p class="mb-3 text-sm text-slate-600">اختر تاريخ الفوترة القادمة. لن تُنشأ فواتير بأثر رجعي.</p>
        <input type="date" wire:model="resumeDate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('resumeDate')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showResume=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="resume" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">استئناف</button></div>
    </x-modal>
</div>
