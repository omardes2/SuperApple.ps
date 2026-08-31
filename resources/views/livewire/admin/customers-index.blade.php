<div>
    <x-page-header title="العملاء" subtitle="إدارة علاقات العملاء (CRM)">
        <x-slot:actions>
            @can('customers.import')
                <a href="{{ route('admin.customers.import') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-600 px-4 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                    استيراد العملاء والأرصدة
                </a>
            @endcan
            @can('customers.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ عميل جديد</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="إجمالي العملاء" :value="$stats['total']" icon="users" tone="brand" />
        <x-stat-card label="العملاء النشطون" :value="$stats['active']" icon="users" tone="emerald" />
        <x-stat-card label="غير النشطين" :value="$stats['inactive']" icon="minus" tone="slate" />
        @if ($canViewBalance)
            <x-stat-card
                label="إجمالي المستحقات"
                :value="'$'.number_format((float) $stats['outstanding_usd'], 2)"
                :hint="'≈ '.number_format((float) $stats['outstanding_ils'], 2).' ₪'"
                icon="invoice" tone="amber" />
        @endif
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم / الرقم / واتساب..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="active" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            <option value="1">نشط</option>
            <option value="0">غير نشط</option>
        </select>
        @if ($canViewBalance)
            <select wire:model.live="balance" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">كل الأرصدة</option>
                <option value="due">عليه رصيد</option>
                <option value="zero">بدون رصيد</option>
            </select>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="hidden px-4 py-3 sm:table-cell">الرقم</th>
                    <th class="px-4 py-3">الاسم</th>
                    <th class="px-4 py-3">واتساب</th>
                    <th class="px-4 py-3">الحالة</th>
                    <th class="hidden px-4 py-3 md:table-cell">المهام</th>
                    @if ($canViewBalance)<th class="px-4 py-3">الرصيد المتبقي</th>@endif
                    <th class="px-4 py-3">الإجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($customers as $customer)
                    @php $bal = $balanceMap[$customer->id] ?? ['usd' => '0.00', 'ils' => '0.00']; @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="hidden px-4 py-3 font-mono text-slate-500 sm:table-cell" dir="ltr">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="hover:text-brand-600 hover:underline">{{ $customer->customer_number }}</a>
                        </td>
                        <td class="px-4 py-3 font-semibold text-slate-800">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="hover:text-brand-600 hover:underline">{{ $customer->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $customer->whatsapp_number ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($customer->is_active)
                                <x-badge class="bg-emerald-50 text-emerald-700">نشط</x-badge>
                            @else
                                <x-badge class="bg-slate-100 text-slate-500">غير نشط</x-badge>
                            @endif
                        </td>
                        <td class="hidden px-4 py-3 text-slate-600 md:table-cell">{{ $customer->tasks_count }}</td>
                        @if ($canViewBalance)
                            <td class="px-4 py-3">
                                <x-money :usd="$bal['usd']" :ils="$bal['ils']" class="font-medium text-slate-800" dir="ltr" />
                            </td>
                        @endif
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <a href="{{ route('admin.customers.show', $customer) }}"
                                   class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                                   title="عرض العميل" aria-label="عرض العميل">
                                    <x-icon name="eye" class="h-4 w-4" />
                                </a>
                                @can('customers.edit')
                                    <button wire:click="edit({{ $customer->id }})"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                                            title="تعديل العميل" aria-label="تعديل العميل">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </button>
                                @endcan
                                @can('customers.archive')
                                    @if ($customer->is_active)
                                        <button wire:click="archive({{ $customer->id }})" wire:confirm="تعطيل هذا العميل؟"
                                                class="rounded-lg p-1.5 text-slate-400 hover:bg-amber-50 hover:text-amber-600"
                                                title="تعطيل العميل" aria-label="تعطيل العميل">
                                            <x-icon name="archive" class="h-4 w-4" />
                                        </button>
                                    @else
                                        <button wire:click="restore({{ $customer->id }})"
                                                class="rounded-lg p-1.5 text-slate-400 hover:bg-emerald-50 hover:text-emerald-600"
                                                title="تفعيل العميل" aria-label="تفعيل العميل">
                                            <x-icon name="power" class="h-4 w-4" />
                                        </button>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $canViewBalance ? 7 : 6 }}" class="px-4 py-10 text-center text-slate-400">لا يوجد عملاء مطابقون.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>

    <x-modal show="showForm" :title="$editingId ? 'تعديل عميل' : 'عميل جديد'" maxWidth="max-w-md">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الاسم <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">رقم واتساب <span class="text-red-500">*</span></label>
                <input type="text" wire:model="whatsapp_number" dir="ltr" placeholder="0599432037" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('whatsapp_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات</label>
                <textarea wire:model="notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($canOpeningBalance && ! $editingId)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.live="showOpeningBalance" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        إضافة رصيد افتتاحي (اختياري)
                    </label>

                    @if ($showOpeningBalance)
                        <div class="mt-3 space-y-3 border-t border-slate-200 pt-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">نوع الرصيد</label>
                                <select wire:model="obType" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="debit">مدين على العميل</option>
                                    <option value="credit">دائن لصالح العميل</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-600">المبلغ USD <span class="text-red-500">*</span></label>
                                    <input type="number" step="0.01" wire:model.live="obAmountUsd" dir="ltr" placeholder="1000.00" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    @error('obAmountUsd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-600">سعر الصرف <span class="text-red-500">*</span></label>
                                    <input type="number" step="0.000001" wire:model.live="obRate" dir="ltr" placeholder="3.10" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    @error('obRate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            @php $obIls = ((float) ($obRate ?: 0) > 0 && $obAmountUsd !== '') ? \App\Support\Money::convertUsdToIls($obAmountUsd ?: 0, $obRate) : '0.00'; @endphp
                            <p class="text-xs text-slate-500">المقابل بالشيكل: <b dir="ltr">{{ number_format((float) $obIls, 2) }} ₪</b></p>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">تاريخ الرصيد <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="obDate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @error('obDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">ملاحظات الرصيد</label>
                                <input type="text" wire:model="obNotes" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            </div>
                            <p class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-700">سيتم إنشاء قيد محاسبي للرصيد الافتتاحي عند الحفظ.</p>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
