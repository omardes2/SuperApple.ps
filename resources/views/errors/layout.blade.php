<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') — SuperApple</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 p-6 font-sans text-slate-800">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div class="mb-3 text-5xl font-bold text-brand-600">@yield('code')</div>
        <h1 class="mb-2 text-lg font-semibold text-slate-800">@yield('title')</h1>
        <p class="mb-6 text-sm text-slate-500">@yield('message')</p>
        <a href="{{ url('/') }}" class="inline-block rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">العودة للرئيسية</a>
    </div>
</body>
</html>
