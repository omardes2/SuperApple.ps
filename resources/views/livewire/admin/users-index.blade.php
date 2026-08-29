<div>
    <x-page-header title="المستخدمون" subtitle="حسابات الدخول وربطها بالموظفين والأدوار">
        <x-slot:actions>
            <a href="{{ route('admin.roles') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">الأدوار والصلاحيات</a>
            @if ($canManage)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">+ مستخدم</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الاسم</th><th class="px-4 py-3">البريد</th><th class="px-4 py-3">الدور</th><th class="px-4 py-3">الموظف</th><th class="px-4 py-3">آخر دخول</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $u)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $u->email }}</td>
                        <td class="px-4 py-3"><x-badge>{{ $u->getRoleNames()->first() ?? '—' }}</x-badge></td>
                        <td class="px-4 py-3 text-slate-600">{{ $u->employee?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-400" dir="ltr">{{ $u->last_login_at?->format('Y-m-d H:i') ?? 'لم يسجّل' }}</td>
                        <td class="px-4 py-3">@if ($u->is_active)<x-badge class="bg-emerald-50 text-emerald-700">مفعّل</x-badge>@else<x-badge class="bg-red-50 text-red-700">معطّل</x-badge>@endif</td>
                        <td class="px-4 py-3">
                            @if ($canManage)
                                <div class="flex gap-1">
                                    <button wire:click="edit({{ $u->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600">تعديل</button>
                                    <button wire:click="toggleActive({{ $u->id }})" wire:confirm="تغيير حالة الحساب؟" class="rounded border px-2 py-1 text-xs {{ $u->is_active ? 'border-red-200 text-red-600' : 'border-emerald-200 text-emerald-700' }}">{{ $u->is_active ? 'تعطيل' : 'تفعيل' }}</button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا مستخدمين.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>

    <x-modal show="showForm" title="مستخدم" maxWidth="max-w-2xl">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">الاسم</label><input wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm text-slate-600">البريد الإلكتروني</label><input wire:model="email" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('email')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">{{ $editingId ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور' }}</label><input type="password" wire:model="password" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('password')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm text-slate-600">الدور</label><select wire:model="role" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">— اختر —</option>@foreach ($roles as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select>@error('role')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">ربط بموظف (اختياري)</label><select wire:model="employee_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">—</option>@foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->full_name }}</option>@endforeach</select></div>
                <label class="mt-6 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="is_active"> الحساب مفعّل</label>
            </div>

            @if ($canDirect)
                <div class="rounded-lg border border-slate-200 p-3">
                    <button type="button" wire:click="$toggle('showDirect')" class="text-sm text-brand-600">صلاحيات مباشرة إضافية (متقدّم) {{ $showDirect ? '▲' : '▼' }}</button>
                    @if ($showDirect)
                        <div class="mt-3 max-h-56 space-y-3 overflow-y-auto">
                            @foreach ($permissionCatalog as $groupKey => $group)
                                <div>
                                    <div class="mb-1 text-xs font-semibold text-slate-500">{{ $groupKey }} @if ($group['financial'])<span class="text-red-500">(مالي)</span>@endif</div>
                                    <div class="grid grid-cols-2 gap-1">
                                        @foreach ($group['permissions'] as $perm => $label)
                                            <label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" value="{{ $perm }}" wire:model="directPermissions"> {{ $label }}</label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
        <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showForm=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="save" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>
</div>
