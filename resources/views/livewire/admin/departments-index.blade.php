<div>
    <x-page-header title="الأقسام" subtitle="إدارة أقسام الشركة ومديريها">
        <x-slot:actions>
            @can('departments.create')
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ قسم جديد</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث بالاسم أو الرمز..."
               class="w-full max-w-sm rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرمز</th>
                    <th class="px-4 py-3">القسم</th>
                    <th class="px-4 py-3">المدير</th>
                    <th class="px-4 py-3">الموظفون</th>
                    <th class="px-4 py-3">الحالة</th>
                    <th class="px-4 py-3">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($departments as $department)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $department->code }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $department->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $department->manager?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $department->employees_count }}</td>
                        <td class="px-4 py-3">
                            @if ($department->is_active)
                                <x-badge class="bg-emerald-50 text-emerald-700">نشط</x-badge>
                            @else
                                <x-badge class="bg-slate-100 text-slate-500">معطّل</x-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                @can('departments.edit')
                                    <button wire:click="edit({{ $department->id }})" class="text-brand-600 hover:underline">تعديل</button>
                                    <button wire:click="toggleActive({{ $department->id }})" class="text-slate-500 hover:underline">
                                        {{ $department->is_active ? 'تعطيل' : 'تفعيل' }}
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">لا توجد أقسام.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $departments->links() }}</div>

    <x-modal show="showForm" :title="$editingId ? 'تعديل قسم' : 'قسم جديد'">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">اسم القسم</label>
                    <input type="text" wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الرمز</label>
                    <input type="text" wire:model="code" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                    @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">الوصف</label>
                    <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none"></textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">مدير القسم</label>
                    <select wire:model="manager_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                        <option value="">— بدون —</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}">{{ $manager->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الترتيب</label>
                    <input type="number" wire:model="sort_order" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> نشط
                </label>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showForm = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إلغاء</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
            </div>
        </form>
    </x-modal>
</div>
