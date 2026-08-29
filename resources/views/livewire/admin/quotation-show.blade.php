<div>
    @php $eff = $quotation->effectiveStatus(); @endphp
    <div class="mb-5 flex flex-wrap items-center gap-4">
        <a href="{{ route('admin.quotations') }}" class="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-800" dir="ltr">{{ $quotation->quotation_number }}</h2>
            <p class="text-sm text-slate-500">{{ $quotation->customer->name }}</p>
        </div>
        <div class="mr-auto"><x-badge :class="$eff->badgeClass()">{{ $eff->label() }}</x-badge></div>
    </div>

    @error('action') <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    {{-- Actions --}}
    <div class="mb-5 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-4">
        @if ($quotation->status === \App\Enums\QuotationStatus::Draft)
            @can('send', $quotation)<button wire:click="send" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">إرسال</button>@endcan
            @can('cancel', $quotation)<button wire:click="cancel" wire:confirm="إلغاء العرض؟" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>@endcan
        @elseif ($quotation->status === \App\Enums\QuotationStatus::Sent)
            @can('accept', $quotation)<button wire:click="accept" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">قبول</button>@endcan
            @can('reject', $quotation)<button wire:click="reject" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">رفض</button>@endcan
            @can('create', \App\Models\Quotation::class)<button wire:click="duplicate" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">نسخة/مراجعة</button>@endcan
            @can('cancel', $quotation)<button wire:click="cancel" wire:confirm="إلغاء العرض؟" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>@endcan
        @elseif ($quotation->status === \App\Enums\QuotationStatus::Accepted)
            @if ($quotation->converted_invoice_id)
                <a href="{{ route('admin.invoices.show', $quotation->converted_invoice_id) }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">عرض الفاتورة المرتبطة</a>
            @else
                @can('convert', $quotation)<button wire:click="convert" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">تحويل إلى فاتورة</button>@endcan
            @endif
            @can('create', \App\Models\Quotation::class)<button wire:click="duplicate" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">نسخة/مراجعة</button>@endcan
        @endif
        @can('print', $quotation)
            <a href="{{ route('admin.quotations.print', $quotation) }}" target="_blank" class="mr-auto rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">طباعة</a>
        @endcan
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @if ($canEdit)
                {{-- Draft editor --}}
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
                            <label class="mb-1 block text-sm font-medium text-slate-700">تاريخ العرض</label>
                            <input type="date" wire:model="quotation_date" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">صالح حتى</label>
                            <input type="date" wire:model="valid_until" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('valid_until') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @include('livewire.admin.partials.line-editor', ['services' => $services])

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">الشروط</label>
                        <textarea wire:model="terms" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات</label>
                        <textarea wire:model="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end"><button type="submit" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button></div>
                </form>
            @else
                {{-- Read-only items --}}
                @include('livewire.admin.partials.items-readonly', ['document' => $quotation])
            @endif
        </div>

        {{-- Right: totals + timeline --}}
        <div class="space-y-5">
            @include('livewire.admin.partials.totals-card', ['totals' => $canEdit ? $preview : [
                'subtotal_usd' => $quotation->subtotal_usd, 'discount_usd' => $quotation->discount_usd,
                'tax_usd' => $quotation->tax_usd, 'total_usd' => $quotation->total_usd,
            ]])

            <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
                <h3 class="mb-3 font-semibold text-slate-800">التسلسل الزمني</h3>
                <ul class="space-y-2 text-slate-600">
                    <li class="flex justify-between"><span>أُنشئ</span><span dir="ltr">{{ $quotation->created_at->format('Y-m-d') }}</span></li>
                    @if ($quotation->sent_at)<li class="flex justify-between"><span>أُرسل</span><span dir="ltr">{{ $quotation->sent_at->format('Y-m-d') }}</span></li>@endif
                    @if ($quotation->accepted_at)<li class="flex justify-between"><span>قُبل</span><span dir="ltr">{{ $quotation->accepted_at->format('Y-m-d') }}</span></li>@endif
                    @if ($quotation->rejected_at)<li class="flex justify-between"><span>رُفض</span><span dir="ltr">{{ $quotation->rejected_at->format('Y-m-d') }}</span></li>@endif
                    @if ($quotation->cancelled_at)<li class="flex justify-between"><span>أُلغي</span><span dir="ltr">{{ $quotation->cancelled_at->format('Y-m-d') }}</span></li>@endif
                    @if ($quotation->converted_invoice_id)<li class="flex justify-between"><span>حُوّل لفاتورة</span><span>✓</span></li>@endif
                </ul>
            </div>
        </div>
    </div>
</div>
