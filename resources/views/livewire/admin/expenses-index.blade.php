<div>
    <x-page-header title="المصاريف" subtitle="القيمة المحاسبية بالشيكل (ILS)">
        <x-slot:actions>
            @if ($canManageCategories)
                <button wire:click="openCategories" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">تصنيفات المصروفات</button>
            @endif
            @can('create', \App\Models\Expense::class)
                <button wire:click="create" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">+ مصروف</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">{{ session('error') }}</div>
    @endif

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3">
        <x-stat-card label="مصاريف الشهر" :value="number_format((float) $stats['month_ils'], 2).' ₪'" hint="مُرحّلة" icon="minus" tone="red" />
        <x-stat-card label="مُرحّلة" :value="$stats['posted']" icon="book" tone="emerald" />
        <x-stat-card label="مسودات" :value="$stats['draft']" icon="doc" tone="slate" />
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <select wire:model.live="category" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الفئات</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">كل الحالات</option>
            @foreach ($statusOptions as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">الوصف</th><th class="px-4 py-3">الفئة</th><th class="px-4 py-3">المبلغ</th><th class="px-4 py-3">ILS</th><th class="px-4 py-3">الحالة</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($expenses as $e)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><a href="{{ route('admin.expenses.show', $e) }}" class="hover:text-brand-600 hover:underline">{{ $e->expense_number }}</a></td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $e->expense_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ \Illuminate\Support\Str::limit($e->description, 40) }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $e->category?->name }}</td>
                        <td class="px-4 py-3 text-slate-700" dir="ltr">{{ number_format((float) $e->amount, 2) }} {{ $e->currency }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800" dir="ltr">{{ number_format((float) $e->amount_ils, 2) }} ₪</td>
                        <td class="px-4 py-3"><x-badge :class="$e->status->badgeClass()">{{ $e->status->label() }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا مصاريف.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $expenses->links() }}</div>

    @if ($canManageCategories)
        <x-modal show="showCategories" title="تصنيفات المصروفات" maxWidth="max-w-3xl">
            <div class="space-y-5">
                {{-- Add / edit form --}}
                <form wire:submit="saveCategory" class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-12">
                    <div class="sm:col-span-4">
                        <label class="mb-1 block text-xs font-medium text-slate-600">اسم الفئة <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="categoryName" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('categoryName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-5">
                        <label class="mb-1 block text-xs font-medium text-slate-600">حساب الأستاذ (مصروف) <span class="text-red-500">*</span></label>
                        <select wire:model="categoryAccountId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— اختر حساب مصروفات —</option>
                            @foreach ($eligibleAccounts as $acc)
                                <option value="{{ $acc->id }}" dir="ltr">{{ $acc->code }} — {{ $acc->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryAccountId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" wire:model="categoryActive" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> فعّال
                        </label>
                    </div>
                    <div class="flex items-end gap-2 sm:col-span-1">
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">حفظ</button>
                    </div>
                    @if ($editingCategoryId)
                        <div class="sm:col-span-12">
                            <button type="button" wire:click="newCategory" class="text-xs text-slate-500 hover:underline">إلغاء التعديل / فئة جديدة</button>
                        </div>
                    @endif
                </form>

                {{-- List --}}
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                            <tr><th class="px-3 py-2">الرمز</th><th class="px-3 py-2">اسم التصنيف</th><th class="px-3 py-2">النوع</th><th class="px-3 py-2">حساب الأستاذ</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2">الإجراءات</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($manageCategories as $cat)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-slate-500" dir="ltr">{{ $cat->defaultAccount?->code ?? '—' }}</td>
                                    <td class="px-3 py-2 font-medium text-slate-800">{{ $cat->name }}</td>
                                    <td class="px-3 py-2 text-slate-500">{{ $cat->defaultAccount?->account_type?->label() ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-600" dir="ltr">{{ $cat->defaultAccount?->name ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if ($cat->is_active)
                                            <x-badge class="bg-emerald-50 text-emerald-700">فعّال</x-badge>
                                        @else
                                            <x-badge class="bg-slate-100 text-slate-500">معطّل</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-1">
                                            <button wire:click="editCategory({{ $cat->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600" title="تعديل الفئة" aria-label="تعديل الفئة">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </button>
                                            @if ($cat->is_active)
                                                <button wire:click="toggleCategory({{ $cat->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-amber-50 hover:text-amber-600" title="تعطيل الفئة" aria-label="تعطيل الفئة">
                                                    <x-icon name="archive" class="h-4 w-4" />
                                                </button>
                                            @else
                                                <button wire:click="toggleCategory({{ $cat->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-emerald-50 hover:text-emerald-600" title="تفعيل الفئة" aria-label="تفعيل الفئة">
                                                    <x-icon name="power" class="h-4 w-4" />
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-slate-400">لا توجد فئات بعد.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-5 flex justify-end border-t border-slate-100 pt-4">
                <button type="button" @click="$wire.showCategories = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">إغلاق</button>
            </div>
        </x-modal>
    @endif
</div>
