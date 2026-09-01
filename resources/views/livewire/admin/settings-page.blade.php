<div class="mx-auto max-w-3xl space-y-6">
    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="mb-4 font-semibold text-slate-800">بيانات الشركة</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">اسم الشركة</label>
                    <input type="text" wire:model="companyName" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                    @error('companyName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الهاتف</label>
                    <input type="text" wire:model="companyPhone" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">واتساب</label>
                    <input type="text" wire:model="companyWhatsapp" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">العنوان</label>
                    <input type="text" wire:model="companyAddress" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">الرقم الضريبي</label>
                    <input type="text" wire:model="taxNumber" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">شعار الشركة (يظهر على الفاتورة المطبوعة)</label>
                    <div class="flex flex-wrap items-center gap-4">
                        @if ($this->logoPreview)
                            <div class="flex h-16 w-40 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 p-2">
                                <img src="{{ $this->logoPreview }}" alt="شعار الشركة" class="max-h-full max-w-full object-contain">
                            </div>
                        @else
                            <div class="flex h-16 w-40 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-xs text-slate-400">لا يوجد شعار</div>
                        @endif
                        <div class="flex-1">
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
                            <div wire:loading wire:target="logo" class="mt-1 text-xs text-slate-400">جارٍ الرفع…</div>
                            <p class="mt-1 text-xs text-slate-400">PNG بخلفية شفافة (أو JPG/WEBP) — يُفضّل أفقي ‎~600×132px أو مربّع ‎~320×320px (يُعرض بحد أقصى ارتفاع 44px). الحد الأقصى 2MB.</p>
                            @error('logo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @if ($this->logoPreview)
                                <button type="button" wire:click="removeLogo" class="mt-2 text-xs font-medium text-red-600 hover:underline">حذف الشعار</button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="mb-1 font-semibold text-slate-800">الإعدادات المالية</h3>
            <p class="mb-4 text-xs text-slate-500">العملة المحاسبية: <b>ILS</b> · عملة الفواتير: <b>USD</b> (قواعد ثابتة). سعر الصرف يُدخل يدوياً داخل كل فاتورة ودفعة — لا يوجد سعر افتراضي مركزي.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">شروط الفاتورة</label>
                    <textarea wire:model="invoiceTerms" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none"></textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">تذييل الفاتورة</label>
                    <textarea wire:model="invoiceFooter" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none"></textarea>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="mb-4 font-semibold text-slate-800">إعدادات الدوام</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">بداية الدوام</label>
                    <input type="time" wire:model="workStart" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">نهاية الدوام</label>
                    <input type="time" wire:model="workEnd" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">فترة السماح (دقائق)</label>
                    <input type="number" wire:model="graceMinutes" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none">
                </div>
            </div>

            <div class="mt-6 border-t border-slate-100 pt-5">
                <label class="mb-2 block text-sm font-medium text-slate-700">أيام العمل</label>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Livewire\Admin\SettingsPage::WEEK_DAYS as $key => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm {{ in_array($key, $workingDays, true) ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-600' }}">
                            <input type="checkbox" value="{{ $key }}" wire:model.live="workingDays" class="rounded border-slate-300 text-brand-600 focus:ring-brand-200">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('workingDays')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-3 text-sm text-slate-500">العطلة الأسبوعية الحالية: <span class="font-medium text-slate-700">{{ $this->weeklyOffLabels() }}</span></p>
            </div>
        </section>

        @can('settings.manage')
            <div class="flex justify-end">
                <button type="submit" class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700" wire:loading.attr="disabled">
                    حفظ الإعدادات
                </button>
            </div>
        @endcan
    </form>
</div>
