{{-- Read-only items table for a locked document ($document with ->items). --}}
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
            <tr>
                <th class="px-4 py-3">البند</th><th class="px-4 py-3">كمية</th>
                <th class="px-4 py-3">السعر</th><th class="px-4 py-3">خصم</th>
                <th class="px-4 py-3">ضريبة</th><th class="px-4 py-3">الإجمالي</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($document->items as $item)
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-medium text-slate-800">{{ $item->item_name }}</p>
                        @if ($item->description)<p class="text-xs text-slate-400">{{ $item->description }}</p>@endif
                    </td>
                    <td class="px-4 py-3 text-slate-600" dir="ltr">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                    <td class="px-4 py-3 text-slate-600" dir="ltr">${{ number_format((float) $item->unit_price_usd, 2) }}</td>
                    <td class="px-4 py-3 text-slate-600" dir="ltr">${{ number_format((float) $item->discount_usd, 2) }}</td>
                    <td class="px-4 py-3 text-slate-600" dir="ltr">${{ number_format((float) $item->tax_usd, 2) }}</td>
                    <td class="px-4 py-3"><x-money :usd="$item->line_total_usd" :rate="$document->exchange_rate ?? null" :useLatest="true" class="font-semibold text-slate-800" dir="ltr" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
