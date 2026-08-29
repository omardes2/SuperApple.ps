@props(['show' => 'showForm', 'title' => '', 'maxWidth' => 'max-w-lg'])

<div x-data
     x-show="$wire.{{ $show }}"
     x-cloak
     class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8">
    <div @click.outside="$wire.{{ $show }} = false"
         class="w-full {{ $maxWidth }} rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 class="font-semibold text-slate-800">{{ $title }}</h3>
            <button type="button" @click="$wire.{{ $show }} = false" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="px-5 py-5">
            {{ $slot }}
        </div>
    </div>
</div>
