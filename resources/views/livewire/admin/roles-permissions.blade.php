<div>
    <x-page-header title="الأدوار والصلاحيات" subtitle="إدارة الأدوار وتعيين الصلاحيات ومراجعة المصفوفة">
        <x-slot:actions><a href="{{ route('admin.users') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">المستخدمون</a></x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('role')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- Role list + create --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <h3 class="mb-3 text-sm font-semibold text-slate-700">الأدوار</h3>
                <div class="space-y-1">
                    @foreach ($roles as $r)
                        <button wire:click="selectRole({{ $r->id }})" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm {{ $selectedRoleId === $r->id ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}">
                            <span>{{ $r->name }}</span><span class="text-xs text-slate-400">{{ $r->name === 'Super Admin' ? 'الكل' : $r->permissions->count() }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <h3 class="mb-2 text-sm font-semibold text-slate-700">دور مخصّص جديد</h3>
                <div class="flex gap-2">
                    <input wire:model="newRoleName" placeholder="اسم الدور" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <button wire:click="createRole" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white">إضافة</button>
                </div>
                @error('newRoleName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Permission editor --}}
        <div class="lg:col-span-2">
            @if ($selectedRoleId)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-700">صلاحيات الدور</h3>
                        <button wire:click="saveRolePermissions" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button>
                    </div>
                    <div class="max-h-[28rem] space-y-4 overflow-y-auto">
                        @foreach ($catalog as $groupKey => $group)
                            <div>
                                <div class="mb-1 text-xs font-semibold text-slate-500">{{ $groupKey }} @if ($group['financial'])<span class="rounded bg-red-50 px-1 text-red-500">مالي</span>@endif</div>
                                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                    @foreach ($group['permissions'] as $perm => $label)
                                        <label class="flex items-center gap-1.5 text-xs text-slate-600">
                                            <input type="checkbox" value="{{ $perm }}" wire:model="rolePermissions"> {{ $label }}
                                            @if (in_array($perm, $dangerous, true))<span title="صلاحية حساسة" class="text-amber-500">⚠</span>@endif
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 border-t border-slate-100 pt-2 text-xs text-amber-600">⚠ الصلاحيات المعلّمة حساسة مالياً — امنحها بحذر.</p>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-400">اختر دوراً لتعديل صلاحياته.</div>
            @endif
        </div>
    </div>

    {{-- Permission matrix --}}
    <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-slate-700">مصفوفة الصلاحيات (الدور × المجموعة)</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead><tr class="text-right text-slate-500"><th class="px-2 py-2">المجموعة</th>@foreach ($roles as $r)<th class="px-2 py-2 text-center">{{ $r->name }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($catalog as $groupKey => $group)
                        @php $total = count($group['permissions']); @endphp
                        <tr>
                            <td class="px-2 py-1.5 text-slate-600">{{ $groupKey }} @if ($group['financial'])<span class="text-red-400">•</span>@endif</td>
                            @foreach ($roles as $r)
                                @php $c = $matrix[$r->name][$groupKey] ?? 0; @endphp
                                <td class="px-2 py-1.5 text-center {{ $c === $total ? 'font-semibold text-emerald-600' : ($c === 0 ? 'text-slate-300' : 'text-amber-600') }}">{{ $c }}/{{ $total }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
