<div>
    <x-page-header title="الخدمات" subtitle="كتالوج الخدمات التي تقدمها الشركة">
        <x-slot:actions>
            @can('services.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ خدمة جديدة</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم/الرمز/التصنيف..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الأنواع</option>
            @foreach ($typeOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرمز</th><th class="px-4 py-3">الخدمة</th>
                    <th class="px-4 py-3">التصنيف</th><th class="px-4 py-3">النوع</th>
                    @if ($canViewFinancial)
                        <th class="px-4 py-3">السعر (USD)</th><th class="px-4 py-3">التكلفة (ILS)</th><th class="px-4 py-3">الضريبة</th>
                    @endif
                    <th class="px-4 py-3">الحالة</th>
                    @canany(['services.edit'])<th class="px-4 py-3">إجراءات</th>@endcanany
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($services as $service)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $service->service_code }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $service->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $service->category ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $service->service_type->label() }}</td>
                        @if ($canViewFinancial)
                            <td class="px-4 py-3 text-slate-700" dir="ltr">@if ($service->default_price_usd !== null)<x-money :usd="$service->default_price_usd" :useLatest="true" />@else—@endif</td>
                            <td class="px-4 py-3 text-slate-700" dir="ltr">{{ $service->estimated_cost_ils !== null ? number_format((float) $service->estimated_cost_ils, 2).' ₪' : '—' }}</td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $service->tax_rate !== null ? $service->tax_rate.'%' : '—' }}</td>
                        @endif
                        <td class="px-4 py-3">
                            @if ($service->is_active)<x-badge class="bg-emerald-50 text-emerald-700">نشطة</x-badge>@else<x-badge class="bg-slate-100 text-slate-500">معطّلة</x-badge>@endif
                        </td>
                        @can('services.edit')
                            <td class="px-4 py-3"><button wire:click="edit({{ $service->id }})" class="text-brand-600 hover:underline">تعديل</button></td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">لا خدمات.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $services->links() }}</div>

    <x-modal show="showForm" :title="$editingId ? 'تعديل خدمة' : 'خدمة جديدة'" maxWidth="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">اسم الخدمة</label>
                    <input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الرمز</label>
                    <input type="text" wire:model="service_code" dir="ltr" placeholder="تلقائي" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('service_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">التصنيف</label>
                    <input type="text" wire:model="category" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">النوع</label>
                    <select wire:model="service_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($typeOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                @if ($canViewFinancial)
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">السعر الافتراضي (USD)</label>
                        <input type="number" step="0.01" wire:model="default_price_usd" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">التكلفة التقديرية (ILS)</label>
                        <input type="number" step="0.01" wire:model="estimated_cost_ils" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">الضريبة %</label>
                        <input type="number" step="0.01" wire:model="tax_rate" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">الوصف</label>
                    <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> خدمة نشطة
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                    <input type="checkbox" wire:model="requires_ad_budget" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> خدمة إعلانات ممولة (تتطلب إدخال ميزانية الحملة في المهمة)
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
