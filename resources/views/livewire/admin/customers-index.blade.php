<div>
    <x-page-header title="العملاء" subtitle="إدارة علاقات العملاء (CRM)">
        <x-slot:actions>
            @can('customers.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ عميل جديد</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="إجمالي العملاء" :value="$stats['total']" icon="users" tone="brand" />
        <x-stat-card label="النشطون" :value="$stats['active']" icon="users" tone="emerald" />
        <x-stat-card label="عملاء محتملون" :value="$stats['leads']" icon="chat" tone="violet" />
        <x-stat-card label="غير نشطين/مؤرشفون" :value="$stats['inactive']" icon="minus" tone="slate" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:flex-wrap lg:items-center">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم/الرقم/الهاتف/واتساب..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل التصنيفات</option>
            @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="source" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل المصادر</option>
            @foreach ($sourceOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
        <input type="text" wire:model.live.debounce.400ms="city" placeholder="المدينة" class="w-32 rounded-lg border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرقم</th><th class="px-4 py-3">الاسم</th>
                    <th class="px-4 py-3">المسؤول</th><th class="px-4 py-3">الهاتف</th>
                    <th class="px-4 py-3">المدينة</th><th class="px-4 py-3">التصنيف</th>
                    <th class="px-4 py-3">الحالة</th><th class="px-4 py-3">المهام</th>
                    <th class="px-4 py-3">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($customers as $customer)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $customer->customer_number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="hover:text-brand-600 hover:underline">{{ $customer->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $customer->contact_person ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $customer->phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $customer->city ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $customer->category?->name ?? '—' }}</td>
                        <td class="px-4 py-3"><x-badge :class="$customer->status->badgeClass()">{{ $customer->status->label() }}</x-badge></td>
                        <td class="px-4 py-3 text-slate-600">{{ $customer->tasks_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.customers.show', $customer) }}" class="text-brand-600 hover:underline">عرض</a>
                                @can('customers.edit')<button wire:click="edit({{ $customer->id }})" class="text-slate-500 hover:underline">تعديل</button>@endcan
                                @can('customers.archive')
                                    <button wire:click="archive({{ $customer->id }})" wire:confirm="أرشفة هذا العميل؟" class="text-amber-600 hover:underline">أرشفة</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">لا يوجد عملاء مطابقون.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>

    <x-modal show="showForm" :title="$editingId ? 'تعديل عميل' : 'عميل جديد'" maxWidth="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الاسم / الشركة</label>
                    <input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الشخص المسؤول</label>
                    <input type="text" wire:model="contact_person" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الهاتف</label>
                    <input type="text" wire:model="phone" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">واتساب</label>
                    <input type="text" wire:model="whatsapp_number" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">المدينة</label>
                    <input type="text" wire:model="customer_city" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">التصنيف</label>
                    <select wire:model="customer_category_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— بدون —</option>
                        @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الحالة</label>
                    <select wire:model="customer_status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">المصدر</label>
                    <select wire:model="customer_source" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— غير محدد —</option>
                        @foreach ($sourceOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الرقم الضريبي</label>
                    <input type="text" wire:model="tax_number" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">العنوان</label>
                    <input type="text" wire:model="address" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">ملاحظات</label>
                    <textarea wire:model="notes" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> عميل نشط
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
