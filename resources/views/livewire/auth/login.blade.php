<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="mb-1 text-lg font-bold text-slate-800">تسجيل الدخول</h2>
    <p class="mb-5 text-sm text-slate-500">أدخل بياناتك للوصول إلى النظام</p>

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">البريد الإلكتروني</label>
            <input type="email" wire:model="email" autocomplete="username" dir="ltr"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">كلمة المرور</label>
            <input type="password" wire:model="password" autocomplete="current-password" dir="ltr"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            تذكرني
        </label>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">دخول</span>
            <span wire:loading wire:target="login">جارٍ الدخول...</span>
        </button>
    </form>
</div>
