<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="بحث في العمليات..."
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
        </div>
        <select wire:model.live="module" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
            <option value="">كل الوحدات</option>
            @foreach ($modules as $m)
                <option value="{{ $m }}">{{ $m }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">التاريخ</th>
                    <th class="px-4 py-3">المستخدم</th>
                    <th class="px-4 py-3">العملية</th>
                    <th class="px-4 py-3">الوحدة</th>
                    <th class="px-4 py-3">الوصف</th>
                    <th class="px-4 py-3">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $log->user?->name ?? 'النظام' }}</td>
                        <td class="px-4 py-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $log->action }}</span></td>
                        <td class="px-4 py-3 text-slate-600">{{ $log->module }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $log->description }}</td>
                        <td class="px-4 py-3 text-slate-400" dir="ltr">{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">لا توجد عمليات مسجّلة بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>
</div>
