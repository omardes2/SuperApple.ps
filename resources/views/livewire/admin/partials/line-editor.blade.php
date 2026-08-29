{{-- Editable line items. Requires the ManagesDocumentLines trait ($lines) and $services. --}}
<div>
    <div class="mb-2 flex items-center justify-between">
        <label class="text-sm font-medium text-slate-700">البنود</label>
        <button type="button" wire:click="addLine" class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-50">+ إضافة بند</button>
    </div>
    @error('lines') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

    <div class="space-y-3">
        @foreach ($lines as $i => $line)
            <div class="rounded-lg border border-slate-200 p-3" wire:key="line-{{ $i }}">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-12">
                    <div class="sm:col-span-3">
                        <label class="mb-1 block text-xs text-slate-500">خدمة (اختياري)</label>
                        <select wire:model.live="lines.{{ $i }}.service_id" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option value="">— يدوي —</option>
                            @foreach ($services as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="mb-1 block text-xs text-slate-500">البند</label>
                        <input type="text" wire:model="lines.{{ $i }}.item_name" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-1">
                        <label class="mb-1 block text-xs text-slate-500">كمية</label>
                        <input type="number" step="0.01" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs text-slate-500">السعر USD</label>
                        <input type="number" step="0.0001" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_price_usd" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-1">
                        <label class="mb-1 block text-xs text-slate-500">ضريبة%</label>
                        <input type="number" step="0.01" wire:model.live.debounce.500ms="lines.{{ $i }}.tax_rate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-2 flex items-end gap-1">
                        <div class="flex-1">
                            <label class="mb-1 block text-xs text-slate-500">خصم</label>
                            <div class="flex gap-1">
                                <select wire:model.live="lines.{{ $i }}.discount_type" class="w-16 rounded-lg border border-slate-300 px-1 py-1.5 text-xs">
                                    <option value="">—</option>
                                    <option value="percentage">%</option>
                                    <option value="fixed">$</option>
                                </select>
                                <input type="number" step="0.01" wire:model.live.debounce.500ms="lines.{{ $i }}.discount_value" dir="ltr" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            </div>
                        </div>
                        <button type="button" wire:click="removeLine({{ $i }})" class="mb-0.5 rounded-lg p-1.5 text-red-500 hover:bg-red-50" title="حذف">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                </div>
                @php $lp = $preview['lines'][$i] ?? null; @endphp
                @if ($lp)
                    <p class="mt-1 text-xs text-slate-400" dir="ltr">
                        قبل الضريبة: ${{ $lp['line_subtotal_usd'] }} · خصم: ${{ $lp['discount_usd'] }} · ضريبة: ${{ $lp['tax_usd'] }} · <b>الإجمالي: ${{ $lp['line_total_usd'] }}</b>
                    </p>
                @endif
            </div>
        @endforeach
    </div>
</div>
