<div>
    <x-page-header title="جاهزية الإنتاج" subtitle="فحص شامل قبل الاعتماد الفعلي — للمدير الأعلى فقط" />

    <div class="mb-5 rounded-xl border px-4 py-3 text-sm {{ $hasFail ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' }}">
        {{ $hasFail ? '⚠ توجد فحوصات فاشلة (FAIL) يجب معالجتها قبل الإنتاج.' : '✓ لا توجد فحوصات فاشلة. راجع التحذيرات (WARN) إن وُجدت.' }}
    </div>

    @php $grouped = collect($checks)->groupBy('group'); @endphp
    <div class="space-y-5">
        @foreach ($grouped as $group => $rows)
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 text-sm font-semibold text-slate-700">{{ $group }}</h3>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $c)
                            <tr>
                                <td class="py-2 text-slate-700">{{ $c['label'] }}</td>
                                <td class="py-2 text-slate-500">{{ $c['detail'] }}</td>
                                <td class="py-2 text-left">
                                    @php $cls = ['pass'=>'bg-emerald-50 text-emerald-700','warn'=>'bg-amber-50 text-amber-700','fail'=>'bg-red-50 text-red-700'][$c['status']]; @endphp
                                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $cls }}">{{ strtoupper($c['status']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
    <p class="mt-4 text-xs text-slate-400">لا تُعرض أي قيم سرية (توكنات/كلمات مرور) في هذه الصفحة.</p>
</div>
