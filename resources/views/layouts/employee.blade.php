<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
    @php
        // Employee experience: strictly operational. No financial links exist here.
        $navGroups = \App\Support\EmployeeNavigation::groups();
    @endphp
    <div x-data="{ open: false }" class="flex min-h-screen">
        {{-- Sidebar (dark, fixed on the right in RTL; off-canvas on mobile) --}}
        <aside class="fixed inset-y-0 right-0 z-40 w-64 -translate-x-0 transform overflow-y-auto bg-slate-900 text-slate-200 transition lg:static lg:translate-x-0"
               :class="open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'">
            <div class="flex h-16 items-center gap-2 border-b border-white/10 px-5">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 font-bold text-white">S</div>
                <span class="font-bold">{{ config('app.name') }}</span>
            </div>
            <nav class="space-y-6 px-3 py-5">
                @foreach ($navGroups as $group)
                    @php
                        $visible = collect($group['items'])->filter(fn ($i) => auth()->user()->can($i['permission']));
                    @endphp
                    @if ($visible->isNotEmpty())
                        <div>
                            <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group['label'] }}</p>
                            <ul class="space-y-1">
                                @foreach ($visible as $item)
                                    @php
                                        $href = $item['route'] ? route($item['route']) : '#';
                                        $active = $item['route'] && (request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*'));
                                    @endphp
                                    <li>
                                        <a href="{{ $href }}"
                                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition {{ $active ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5' }}">
                                            <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                            <span>{{ $item['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </nav>
        </aside>

        {{-- Backdrop (mobile) --}}
        <div x-show="open" @click="open = false" x-cloak class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

        {{-- Main --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 lg:px-6">
                <button @click="open = !open" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="hidden text-lg font-semibold text-slate-800 md:block">{{ $header ?? ($title ?? 'الرئيسية') }}</h1>
                <div class="mr-auto flex items-center gap-3">
                    @can('notifications.view')
                        @php $unread = auth()->user()->unreadNotifications()->count(); @endphp
                        <a href="{{ route('employee.notifications') }}" class="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="الإشعارات">
                            <x-icon name="bell" />
                            @if ($unread > 0)<span class="absolute -top-0.5 left-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
                        </a>
                    @endcan
                    <div x-data="{ menu: false }" class="relative">
                        <button @click="menu = !menu" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-100">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                                {{ mb_substr(auth()->user()->name, 0, 1) }}
                            </span>
                            <span class="hidden text-sm sm:block">
                                <span class="block font-medium text-slate-700">{{ auth()->user()->name }}</span>
                                <span class="block text-xs text-slate-400">{{ auth()->user()->employee?->job_title ?? 'موظف' }}</span>
                            </span>
                        </button>
                        <div x-show="menu" @click.outside="menu = false" x-cloak
                             class="absolute left-0 mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                    <x-icon name="logout" class="h-4 w-4" /> تسجيل الخروج
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-4 lg:p-6">
                @if (session('status'))
                    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">{{ session('error') }}</div>
                @endif
                {{ $slot }}
            </main>
            <footer class="border-t border-slate-200 px-6 py-3 text-center text-xs text-slate-400">
                SuperApple ERP · الإصدار {{ app(\App\Services\Settings::class)->get('system', 'version', '1.0.0') }}
            </footer>
        </div>
    </div>
</body>
</html>
