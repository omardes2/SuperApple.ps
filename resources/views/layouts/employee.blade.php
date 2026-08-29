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
        $employeeNav = [
            ['route' => 'employee.dashboard', 'label' => 'الرئيسية', 'icon' => 'home', 'permission' => 'dashboard.view'],
            ['route' => null, 'label' => 'مهامي', 'icon' => 'check', 'permission' => 'tasks.view'],
            ['route' => null, 'label' => 'مشاريعي', 'icon' => 'folder', 'permission' => 'projects.view'],
            ['route' => 'employee.attendance', 'label' => 'الدوام', 'icon' => 'clock', 'permission' => 'attendance.view_own'],
            ['route' => 'employee.leaves', 'label' => 'الإجازات', 'icon' => 'calendar', 'permission' => 'leaves.view_own'],
            ['route' => null, 'label' => 'الإشعارات', 'icon' => 'bell', 'permission' => 'notifications.view'],
        ];
    @endphp
    <div class="mx-auto flex min-h-screen max-w-6xl flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4">
            <div class="flex items-center gap-2">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 font-bold text-white">S</div>
                <span class="font-bold text-slate-800">{{ config('app.name') }}</span>
            </div>
            <div class="mr-auto flex items-center gap-3">
                <span class="hidden text-sm text-slate-600 sm:block">{{ auth()->user()->name }}</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                    {{ mb_substr(auth()->user()->name, 0, 1) }}
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="تسجيل الخروج">
                        <x-icon name="logout" class="h-5 w-5" />
                    </button>
                </form>
            </div>
        </header>

        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-2">
            @foreach ($employeeNav as $item)
                @continue(! auth()->user()->can($item['permission']))
                @php
                    $href = $item['route'] ? route($item['route']) : '#';
                    $active = $item['route'] && request()->routeIs($item['route']);
                @endphp
                <a href="{{ $href }}"
                   class="flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm transition {{ $active ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    <x-icon :name="$item['icon']" class="h-5 w-5" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <main class="flex-1 p-4">
            @if (session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>
            @endif
            {{ $slot }}
        </main>
    </div>
</body>
</html>
