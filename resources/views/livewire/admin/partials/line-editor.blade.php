{{-- Editable line items. Requires the ManagesDocumentLines trait ($lines),
     $services, and the current $exchange_rate (for the per-line ILS preview).
     The card title and the "add line" button live in the parent card header. --}}
<div>
    @error('lines') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

    <div class="space-y-3">
        @foreach ($lines as $i => $line)
            @php $lp = $preview['lines'][$i] ?? null; @endphp
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3" wire:key="line-{{ $i }}">
                <div class="flex items-start gap-2">
                    <div class="grid flex-1 grid-cols-2 gap-2 lg:grid-cols-12">
                        <div class="lg:col-span-2">
                            <label class="mb-1 block text-xs text-slate-500">الخدمة (اختياري)</label>
                            <select wire:model.live="lines.{{ $i }}.service_id" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                <option value="">— يدوي —</option>
                                @foreach ($services as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-span-2 lg:col-span-3">
                            <label class="mb-1 block text-xs text-slate-500">وصف البند</label>
                            <input type="text" wire:model="lines.{{ $i }}.item_name" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="lg:col-span-1">
                            <label class="mb-1 block text-xs text-slate-500">الكمية</label>
                            <input type="number" step="0.01" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="lg:col-span-2">
                            <label class="mb-1 block text-xs text-slate-500">سعر الوحدة USD</label>
                            <input type="number" step="0.0001" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_price_usd" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="lg:col-span-1">
                            <label class="mb-1 block text-xs text-slate-500">الضريبة %</label>
                            <input type="number" step="0.01" wire:model.live.debounce.500ms="lines.{{ $i }}.tax_rate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="col-span-2 lg:col-span-3">
                            <label class="mb-1 block text-xs text-slate-500">الخصم</label>
                            <div class="flex gap-1">
                                <select wire:model.live="lines.{{ $i }}.discount_type" class="w-14 shrink-0 rounded-lg border border-slate-300 px-1 py-1.5 text-xs">
                                    <option value="">—</option>
                                    <option value="percentage">%</option>
                                    <option value="fixed">$</option>
                                </select>
                                <input type="number" step="0.01" wire:model.live.debounce.500ms="lines.{{ $i }}.discount_value" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            </div>
                        </div>
                    </div>
                    <button type="button" wire:click="removeLine({{ $i }})"
                            class="mt-5 shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                            title="حذف البند" aria-label="حذف البند">
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>

                @if ($lp)
                    <div class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2">
                        <span class="text-[11px] text-slate-400" dir="ltr">
                            قبل الضريبة ${{ $lp['line_subtotal_usd'] }} · خصم ${{ $lp['discount_usd'] }} · ضريبة ${{ $lp['tax_usd'] }}
                        </span>
                        <span class="text-sm font-semibold text-slate-800">
                            <x-money :usd="$lp['line_total_usd']" :rate="$exchange_rate" dir="ltr" />
                        </span>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
