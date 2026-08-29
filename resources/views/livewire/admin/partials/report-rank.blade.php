<table class="min-w-full text-sm">
    <tbody class="divide-y divide-slate-100">
        @forelse ($rows as $i => $r)
            <tr>
                <td class="py-2 text-slate-400" dir="ltr" style="width:2rem">{{ $i + 1 }}</td>
                <td class="py-2 text-slate-700">{{ $label($r) ?? '—' }}</td>
                <td class="py-2 text-left font-medium text-slate-800" dir="ltr">{{ $value($r) }}</td>
            </tr>
        @empty
            <tr><td class="py-6 text-center text-slate-400">لا بيانات.</td></tr>
        @endforelse
    </tbody>
</table>
