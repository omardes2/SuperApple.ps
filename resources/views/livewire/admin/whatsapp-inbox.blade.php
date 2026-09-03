<div>
    <x-page-header title="الصندوق الوارد" subtitle="ردود العملاء الواردة عبر واتساب">
        <x-slot:actions>
            <a href="{{ route('admin.whatsapp') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">لوحة واتساب</a>
            @if ($unreadCount > 0)
                <button wire:click="markAllRead" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white">تعليم الكل كمقروء</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-4 flex items-center gap-2">
        <button wire:click="setFilter('unread')"
            class="rounded-lg px-3 py-2 text-sm {{ $filter === 'unread' ? 'bg-brand-600 text-white' : 'border border-slate-300 text-slate-600' }}">
            غير المقروءة
            @if ($unreadCount > 0)<span class="ms-1 rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white">{{ $unreadCount }}</span>@endif
        </button>
        <button wire:click="setFilter('all')"
            class="rounded-lg px-3 py-2 text-sm {{ $filter === 'all' ? 'bg-brand-600 text-white' : 'border border-slate-300 text-slate-600' }}">
            الكل
        </button>
    </div>

    <div class="space-y-2">
        @forelse ($messages as $m)
            @php $unread = $m->admin_read_at === null; @endphp
            <div wire:key="msg-{{ $m->id }}"
                 class="flex items-start gap-3 rounded-xl border bg-white p-4 {{ $unread ? 'border-red-200 bg-red-50/40' : 'border-slate-200' }}">
                <div class="mt-1">
                    @if ($unread)
                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-red-500" title="غير مقروءة"></span>
                    @else
                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-slate-200"></span>
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex flex-wrap items-center gap-2">
                        @if ($m->customer)
                            <a href="{{ route('admin.customers.show', $m->customer) }}" class="text-sm font-semibold text-brand-700 hover:underline">{{ $m->customer->name }}</a>
                        @else
                            <span class="text-sm font-semibold text-slate-500">عميل غير معروف</span>
                        @endif
                        <span class="font-mono text-xs text-slate-400" dir="ltr">{{ $m->phone }}</span>
                        @if ($unread)<span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-600">جديدة</span>@endif
                    </div>
                    <p class="whitespace-pre-wrap break-words text-sm text-slate-700">{{ $m->message_body }}</p>
                    <p class="mt-1 text-xs text-slate-400" dir="ltr">{{ $m->created_at?->format('Y-m-d H:i') }}</p>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-2">
                    @if ($unread)
                        <button wire:click="markRead({{ $m->id }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50">تعليم كمقروء</button>
                    @endif
                    @if ($m->customer)
                        <a href="{{ route('admin.customers.show', $m->customer) }}" class="rounded-lg border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50">فتح المحادثة</a>
                    @endif
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-slate-200 bg-white py-10 text-center text-sm text-slate-400">
                {{ $filter === 'unread' ? 'لا رسائل واردة غير مقروءة.' : 'لا رسائل واردة بعد.' }}
            </p>
        @endforelse
    </div>

    <div class="mt-4">{{ $messages->links() }}</div>
</div>
