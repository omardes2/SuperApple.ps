<div>
    <x-page-header title="دليل الحسابات" subtitle="العملة الأساسية للقيود: الشيكل (ILS)" />

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">الرمز</th><th class="px-4 py-3">الحساب</th>
                    <th class="px-4 py-3">النوع</th><th class="px-4 py-3">الطبيعة</th>
                    <th class="px-4 py-3">نظامي</th><th class="px-4 py-3">ترحيل يدوي</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($accounts as $account)
                    <tr class="{{ $account->parent_id === null ? 'bg-slate-50 font-semibold' : '' }}">
                        <td class="px-4 py-2 font-mono text-slate-500" dir="ltr">{{ $account->code }}</td>
                        <td class="px-4 py-2 text-slate-800" style="padding-right: {{ $account->parent_id ? '2rem' : '1rem' }}">{{ $account->name }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $account->account_type->label() }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $account->normal_balance->label() }}</td>
                        <td class="px-4 py-2">@if ($account->is_system)<x-badge class="bg-brand-50 text-brand-700">نظامي</x-badge>@endif</td>
                        <td class="px-4 py-2 text-slate-500">{{ $account->allow_manual_posting ? 'نعم' : 'لا' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
