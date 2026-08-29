<div>
    <x-page-header title="قوالب واتساب" subtitle="رسائل قابلة لإعادة الاستخدام بمتغيّرات مثل customer_name و invoice_number">
        <x-slot:actions>
            <a href="{{ route('admin.whatsapp') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">رجوع</a>
            @if ($canManage)<button wire:click="openCreate" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">+ قالب</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">الاسم</th><th class="px-4 py-3">المفتاح</th><th class="px-4 py-3">التصنيف</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($templates as $t)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $t->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">{{ $t->key }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $t->category }}</td>
                        <td class="px-4 py-3">@if ($t->is_active)<x-badge class="bg-emerald-50 text-emerald-700">مفعّل</x-badge>@else<x-badge class="bg-slate-100 text-slate-500">معطّل</x-badge>@endif</td>
                        <td class="px-4 py-3">@if ($canManage)<button wire:click="edit({{ $t->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600">تعديل</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا قوالب.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" title="قالب واتساب" maxWidth="max-w-2xl">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">الاسم</label><input wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">@error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm text-slate-600">المفتاح</label><input wire:model="key" dir="ltr" @if($editingId) disabled @endif class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100">@error('key')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm text-slate-600">التصنيف</label><input wire:model="category" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
                <label class="mt-6 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="is_active"> مفعّل</label>
            </div>
            <div><label class="mb-1 block text-sm text-slate-600">النص</label><textarea wire:model="body" rows="6" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>@error('body')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="flex items-center gap-2"><button type="button" wire:click="renderPreview" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600">معاينة</button><span class="text-xs text-slate-400">المتغيّرات المتاحة: customer_name, invoice_number, invoice_total_usd, invoice_remaining_usd, due_date, balance_usd, balance_ils, invoice_list, subscription_name, payment_amount, payment_currency</span></div>
            @if ($preview)<div class="whitespace-pre-line rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-700">{{ $preview }}</div>@endif
        </div>
        <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showForm=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="save" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>
</div>
