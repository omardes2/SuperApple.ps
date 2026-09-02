<div>
    <x-page-header title="واتساب" subtitle="لوحة المراسلات وإعدادات القناة">
        <x-slot:actions>
            <a href="{{ route('admin.whatsapp.templates') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">القوالب</a>
            <a href="{{ route('admin.whatsapp.reminders') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">قواعد التذكير</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @error('action')<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <div class="mb-5 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-stat-card label="أُرسلت" :value="$counts['sent'] ?? 0" icon="check" tone="brand" />
        <x-stat-card label="وصلت/قُرئت" :value="($counts['delivered'] ?? 0) + ($counts['read'] ?? 0)" icon="check" tone="emerald" />
        <x-stat-card label="قيد الانتظار" :value="($counts['pending'] ?? 0) + ($counts['queued'] ?? 0)" icon="clock" tone="slate" />
        <x-stat-card label="فشلت" :value="$counts['failed'] ?? 0" icon="shield" tone="red" />
    </div>

    @if ($canSettings)
        <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">إعدادات القناة</h3>
            <div class="flex flex-wrap items-end gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="enabled"> تفعيل واتساب</label>
                <div><label class="mb-1 block text-xs text-slate-500">المزوّد</label><select wire:model.live="provider" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="null">Null (لا إرسال)</option><option value="log">Log</option><option value="fake">Fake (اختبار)</option><option value="meta_cloud">Meta WhatsApp Cloud API</option></select></div>
                <div><label class="mb-1 block text-xs text-slate-500">رمز الدولة الافتراضي</label><input wire:model="default_country_code" dir="ltr" class="w-24 rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
            </div>

            @if ($provider === 'meta_cloud')
                <div class="mt-4 grid grid-cols-1 gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Phone Number ID</label>
                        <input wire:model="metaPhoneNumberId" dir="ltr" placeholder="123456789012345" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('metaPhoneNumberId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">إصدار الـAPI</label>
                        <input wire:model="metaApiVersion" dir="ltr" placeholder="v21.0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Access Token @if ($metaTokenSet)<span class="text-emerald-600">(محفوظ)</span>@endif</label>
                        <input type="password" wire:model="metaAccessToken" dir="ltr" placeholder="{{ $metaTokenSet ? '••••••••  (اتركه فارغاً للإبقاء على الحالي)' : 'EAAG...' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('metaAccessToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                    <h4 class="mb-1 text-sm font-semibold text-slate-800">Webhook (اختياري — لتتبّع التسليم/القراءة واستقبال الردود)</h4>
                    <p class="mb-3 text-xs text-slate-400">انسخ عنوان الاستدعاء ورمز التحقق إلى إعدادات Webhook في تطبيق Meta.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs text-slate-500">عنوان URL الاستدعاء (Callback URL)</label>
                            <input type="text" value="{{ $this->callbackUrl }}" readonly dir="ltr" onclick="this.select()" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">رمز التحقق (Verify Token)</label>
                            <input wire:model="metaVerifyToken" dir="ltr" placeholder="اختر رمزاً سرياً" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('metaVerifyToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">App Secret @if ($metaAppSecretSet)<span class="text-emerald-600">(محفوظ)</span>@endif</label>
                            <input type="password" wire:model="metaAppSecret" dir="ltr" placeholder="{{ $metaAppSecretSet ? '••••••••  (اتركه فارغاً للإبقاء على الحالي)' : 'اختياري — للتحقق من التوقيع' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @error('metaAppSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-4">
                <button wire:click="saveSettings" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">حفظ</button>
            </div>
            <p class="mt-2 text-xs text-slate-400">لا تُحفظ أي بيانات اعتماد في الشيفرة — تُقرأ من الإعدادات/البيئة فقط. رمز الوصول لا يُعرض بعد حفظه.</p>
        </div>
    @endif

    <div class="mb-4"><select wire:model.live="statusFilter" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">كل الحالات</option>@foreach ($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach</select></div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-4 py-3">العميل</th><th class="px-4 py-3">الرقم</th><th class="px-4 py-3">النص</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($messages as $m)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-700">{{ $m->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $m->phone }}</td>
                        <td class="px-4 py-3 text-slate-600"><span class="line-clamp-1 block max-w-xs">{{ \Illuminate\Support\Str::limit($m->message_body, 60) }}</span></td>
                        <td class="px-4 py-3"><x-badge :class="$m->status->badgeClass()">{{ $m->status->label() }}</x-badge>@if ($m->failure_reason)<span class="block text-xs text-red-500">{{ \Illuminate\Support\Str::limit($m->failure_reason, 40) }}</span>@endif</td>
                        <td class="px-4 py-3 text-slate-400" dir="ltr">{{ $m->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">@if ($canRetry && $m->status->value === 'failed')<button wire:click="retry({{ $m->id }})" class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-700">إعادة إرسال</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">لا رسائل.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $messages->links() }}</div>
</div>
