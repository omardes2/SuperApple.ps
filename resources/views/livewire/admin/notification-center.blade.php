<div>
    <x-page-header title="مركز الإشعارات" subtitle="كل التنبيهات في مكان واحد">
        <x-slot:actions>
            <button wire:click="markAllRead" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">تعليم الكل كمقروء</button>
            <button wire:click="$set('showPrefs', true)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">التفضيلات</button>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach (['all'=>'الكل','unread'=>'غير المقروءة','tasks'=>'المهام','hr'=>'الموارد البشرية','finance'=>'المالية','whatsapp'=>'واتساب'] as $key=>$label)
            <button wire:click="setTab('{{ $key }}')" class="shrink-0 border-b-2 px-4 py-2.5 text-sm transition {{ $tab === $key ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                {{ $label }}@if ($key === 'unread' && $unreadCount > 0)<span class="mr-1 rounded-full bg-red-100 px-1.5 text-xs text-red-600">{{ $unreadCount }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
        @forelse ($notifications as $n)
            <div class="flex items-start gap-3 px-4 py-3 {{ $n->read_at ? '' : 'bg-brand-50/40' }}">
                <div class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-slate-200' : 'bg-brand-500' }}"></div>
                <div class="flex-1">
                    <div class="text-sm font-medium text-slate-800">{{ $n->data['title'] ?? 'إشعار' }}</div>
                    <div class="text-sm text-slate-500">{{ $n->data['message'] ?? '' }}</div>
                    <div class="mt-1 text-xs text-slate-400" dir="ltr">{{ $n->created_at?->diffForHumans() }}</div>
                </div>
                @if (! $n->read_at)<button wire:click="markRead('{{ $n->id }}')" class="text-xs text-brand-600 hover:underline">تعليم كمقروء</button>@endif
            </div>
        @empty
            <div class="px-4 py-12 text-center text-slate-400">لا إشعارات.</div>
        @endforelse
    </div>

    <x-modal show="showPrefs" title="تفضيلات الإشعارات">
        <p class="mb-3 text-sm text-slate-500">اختر فئات الإشعارات التي تريد رؤيتها.</p>
        <div class="space-y-2">
            @foreach (['tasks'=>'المهام','hr'=>'الموارد البشرية','finance'=>'المالية','whatsapp'=>'إخفاقات واتساب'] as $key=>$label)
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="prefs.{{ $key }}"> {{ $label }}</label>
            @endforeach
        </div>
        <div class="mt-4 flex justify-end gap-2"><button @click="$wire.showPrefs=false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">تراجع</button><button wire:click="savePrefs" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button></div>
    </x-modal>
</div>
