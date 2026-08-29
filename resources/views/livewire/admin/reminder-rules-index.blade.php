<div>
    <x-page-header title="قواعد التذكير بالدفع" subtitle="تذكيرات تلقائية عبر واتساب حسب تاريخ الاستحقاق">
        <x-slot:actions>
            <a href="{{ route('admin.whatsapp') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">رجوع</a>
            @if ($canManage)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">+ قاعدة</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الاسم</th><th class="px-4 py-3">التوقيت</th><th class="px-4 py-3">الأيام</th><th class="px-4 py-3">القالب</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rules as $r)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $r->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $r->timing_type->label() }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $r->offset_days }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $r->template?->name ?? '—' }}</td>
                        <td class="px-4 py-3">@if ($r->is_active)<x-badge class="bg-emerald-50 text-emerald-700">مفعّلة</x-badge>@else<x-badge class="bg-slate-100 text-slate-500">معطّلة</x-badge>@endif</td>
                        <td class="px-4 py-3">@if ($canManage)<button wire:click="edit({{ $r->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600">تعديل</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">لا قواعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" title="قاعدة تذكير">
        <div class="space-y-3">
            <div><label class="mb-1 block text-sm text-slate-600">الاسم</label><input wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">التوقيت</label><select wire:model="timing_type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@foreach ($timings as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm text-slate-600">عدد الأيام</label><input type="number" min="0" wire:model="offset_days" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('offset_days')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">القالب</label><select wire:model="template_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">— اختر —</option>@foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>@error('template_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">وقت الإرسال (اختياري)</label><input type="time" wire:model="send_time" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <label class="mt-6 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="is_active"> مفعّلة</label>
            </div>
        </div>
        <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showForm=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="save" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>
</div>
