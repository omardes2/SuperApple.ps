<div>
    <x-page-header title="استيراد العملاء والأرصدة" subtitle="رفع ملف Excel لإنشاء العملاء وأرصدتهم الافتتاحية">
        <x-slot:actions>
            <a href="{{ route('admin.customers') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">العودة إلى العملاء</a>
        </x-slot:actions>
    </x-page-header>

    @php $svc = \App\Services\CustomerImportService::class; @endphp

    @if ($parseError)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $parseError }}</div>
    @endif

    {{-- ============ STEP 1: UPLOAD ============ --}}
    @if ($step === 'upload')
        <div class="mx-auto max-w-2xl space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-6">
                <div class="mb-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                    <ul class="list-inside list-disc space-y-1">
                        <li>الأرصدة في الملف بالشيكل (ILS) وسيتم تحويلها إلى الدولار حسب سعر الصرف في كل صف.</li>
                        <li>لن يتم إنشاء أي فواتير أو دفعات — أرصدة افتتاحية فقط بقيدها المحاسبي الرسمي.</li>
                        <li>لن يتم اعتماد أي بيانات قبل المعاينة والتأكيد.</li>
                        <li>الأنواع المدعومة: <span dir="ltr">.xlsx / .xls / .csv</span> — الحد الأقصى 5MB.</li>
                    </ul>
                </div>

                <label class="mb-1 block text-sm font-medium text-slate-700">ملف Excel</label>
                <input type="file" wire:model="file" accept=".xlsx,.xls,.csv"
                       class="block w-full rounded-lg border border-slate-300 text-sm file:mr-3 file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-brand-700">
                <div wire:loading wire:target="file" class="mt-2 text-xs text-slate-500">جارٍ رفع الملف…</div>
                @error('file') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-5 flex items-center justify-between">
                    <a href="{{ route('admin.customers.import.template') }}" class="text-sm text-brand-600 hover:underline">تحميل نموذج Excel</a>
                    <button type="button" wire:click="parse" wire:loading.attr="disabled" wire:target="parse,file"
                            class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="parse">معاينة الملف</span>
                        <span wire:loading wire:target="parse">جارٍ التحليل…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ STEP 2: PREVIEW ============ --}}
    @if ($step === 'preview')
        @foreach ($warnings as $w)
            <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $w }}</div>
        @endforeach

        {{-- Stat cards --}}
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
            <x-stat-card label="إجمالي الصفوف" :value="$stats['total_rows']" icon="grid" tone="slate" />
            <x-stat-card label="عملاء جدد" :value="$stats['new_customers']" icon="users" tone="brand" />
            <x-stat-card label="عملاء موجودون" :value="$stats['existing_customers']" icon="users" tone="amber" />
            <x-stat-card label="مدين" :value="$stats['debit_count']" icon="invoice" tone="emerald" />
            <x-stat-card label="دائن" :value="$stats['credit_count']" icon="minus" tone="slate" />
            <x-stat-card label="بدون رصيد" :value="$stats['zero_count']" icon="doc" tone="slate" />
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <x-stat-card label="إجمالي المدين ₪" :value="number_format((float) $stats['total_debit_ils'], 2)" icon="invoice" tone="emerald" />
            <x-stat-card label="إجمالي الدائن ₪" :value="number_format((float) $stats['total_credit_ils'], 2)" icon="invoice" tone="amber" />
            <x-stat-card label="صافي الذمم ₪" :value="number_format((float) $stats['net_ils'], 2)" icon="invoice" tone="brand" />
            <x-stat-card label="إجمالي المدين $" :value="number_format((float) $stats['total_debit_usd'], 2)" icon="invoice" tone="emerald" />
            <x-stat-card label="إجمالي الدائن $" :value="number_format((float) $stats['total_credit_usd'], 2)" icon="invoice" tone="amber" />
        </div>

        <div class="mb-4 flex flex-wrap gap-3 text-sm">
            <span class="rounded-full bg-red-50 px-3 py-1 text-red-700">أخطاء: {{ $stats['errors'] }}</span>
            <span class="rounded-full bg-amber-50 px-3 py-1 text-amber-700">تحذيرات: {{ $stats['warnings'] }}</span>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600">مكرر: {{ $stats['duplicates'] }}</span>
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">جاهز للاستيراد: {{ $importableCount }}</span>
        </div>

        {{-- Preview table --}}
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-right text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-3">#</th>
                        <th class="px-3 py-3">العميل</th>
                        <th class="px-3 py-3">واتساب</th>
                        <th class="px-3 py-3">النوع</th>
                        <th class="px-3 py-3">₪ ILS</th>
                        <th class="px-3 py-3">السعر</th>
                        <th class="px-3 py-3">$ USD</th>
                        <th class="px-3 py-3">التاريخ</th>
                        <th class="px-3 py-3">الحالة</th>
                        <th class="px-3 py-3">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $i => $row)
                        @php
                            $tone = match ($row['status']) {
                                'error' => 'bg-red-50/50',
                                'warning' => 'bg-amber-50/40',
                                'duplicate' => 'bg-slate-50',
                                default => '',
                            };
                            $statusLabel = ['ready' => 'جاهز', 'duplicate' => 'موجود', 'warning' => 'تحذير', 'error' => 'خطأ'][$row['status']] ?? $row['status'];
                            $statusClass = ['ready' => 'bg-emerald-100 text-emerald-700', 'duplicate' => 'bg-slate-200 text-slate-700', 'warning' => 'bg-amber-100 text-amber-700', 'error' => 'bg-red-100 text-red-700'][$row['status']];
                        @endphp
                        <tr class="{{ $tone }} align-top">
                            <td class="px-3 py-2 text-slate-400" dir="ltr">{{ $row['line'] }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-slate-800">{{ $row['name'] ?: '—' }}</div>
                                @foreach ($row['messages'] as $m)
                                    <div class="mt-0.5 text-xs {{ $row['status'] === 'error' ? 'text-red-600' : 'text-slate-500' }}">• {{ $m }}</div>
                                @endforeach
                            </td>
                            <td class="px-3 py-2 text-slate-500" dir="ltr">{{ $row['whatsapp'] ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($row['type'] === 'debit')<span class="text-emerald-700">مدين</span>
                                @elseif ($row['type'] === 'credit')<span class="text-amber-700">دائن</span>
                                @else <span class="text-slate-400">—</span>@endif
                            </td>
                            <td class="px-3 py-2 text-slate-700" dir="ltr">{{ $row['ils'] !== '' ? number_format((float) $row['ils'], 2) : '—' }}</td>
                            <td class="px-3 py-2 text-slate-500" dir="ltr">{{ $row['rate'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-700" dir="ltr">{{ $row['usd'] !== '' ? number_format((float) $row['usd'], 2) : '—' }}</td>
                            <td class="px-3 py-2 text-slate-500" dir="ltr">{{ $row['balance_date'] ?? '—' }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $statusClass }}">{{ $statusLabel }}</span></td>
                            <td class="px-3 py-2">
                                @if ($row['existing_customer_id'] && $row['status'] !== 'error')
                                    <select wire:change="setAction({{ $i }}, $event.target.value)" class="rounded border border-slate-300 px-2 py-1 text-xs">
                                        <option value="skip" @selected($row['action'] === 'skip')>تجاهل</option>
                                        <option value="attach" @selected($row['action'] === 'attach')
                                            @disabled($row['has_existing_ob'] && $row['has_balance'])>استخدام العميل + إضافة الرصيد</option>
                                    </select>
                                @elseif ($row['status'] === 'error')
                                    <span class="text-xs text-red-500">يُتجاهل</span>
                                @else
                                    <span class="text-xs text-emerald-600">سيُنشأ</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Confirm bar --}}
        <div class="mt-5 rounded-xl border border-brand-200 bg-brand-50/50 p-4">
            <p class="mb-3 text-sm text-slate-700">
                سيتم إنشاء العملاء والأرصدة الافتتاحية وقيودها المحاسبية الرسمية.
                <span class="font-semibold">لا يتم إنشاء فواتير مبيعات ولا دفعات.</span>
                الصفوف ذات الأخطاء والصفوف المُتجاهلة لن تُستورد.
            </p>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button type="button" wire:click="cancel" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">إلغاء</button>
                <button type="button"
                        wire:click="confirmImport"
                        wire:loading.attr="disabled" wire:target="confirmImport"
                        @disabled($importableCount === 0)
                        class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="confirmImport">اعتماد الاستيراد ({{ $importableCount }})</span>
                    <span wire:loading wire:target="confirmImport">جارٍ الاستيراد…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ============ STEP 3: DONE ============ --}}
    @if ($step === 'done')
        <div class="mx-auto max-w-2xl space-y-5">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100">
                    <svg class="h-6 w-6 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-emerald-800">تم الاستيراد بنجاح</h3>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <x-stat-card label="العملاء المنشؤون" :value="$report['created_customers']" icon="users" tone="brand" />
                <x-stat-card label="عملاء موجودون مستخدمون" :value="$report['existing_used']" icon="users" tone="amber" />
                <x-stat-card label="الأرصدة الافتتاحية" :value="$report['opening_balances']" icon="invoice" tone="emerald" />
                <x-stat-card label="بدون رصيد" :value="$report['zero_balance']" icon="doc" tone="slate" />
                <x-stat-card label="المتجاهلة" :value="$report['skipped']" icon="minus" tone="slate" />
                <x-stat-card label="صافي الرصيد ₪" :value="number_format((float) $report['net_ils'], 2)" icon="invoice" tone="brand" />
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                <div class="grid grid-cols-2 gap-2">
                    <div>إجمالي المدين: <span dir="ltr" class="font-medium">{{ number_format((float) $report['debit_ils'], 2) }} ₪ / {{ number_format((float) $report['debit_usd'], 2) }} $</span></div>
                    <div>إجمالي الدائن: <span dir="ltr" class="font-medium">{{ number_format((float) $report['credit_ils'], 2) }} ₪ / {{ number_format((float) $report['credit_usd'], 2) }} $</span></div>
                </div>
            </div>

            <div class="flex justify-end">
                <a href="{{ route('admin.customers') }}" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">العودة إلى العملاء</a>
            </div>
        </div>
    @endif
</div>
