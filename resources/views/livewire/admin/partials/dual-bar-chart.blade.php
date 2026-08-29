@php
    // $rows: list of ['label'=>..., 'a'=>numeric, 'b'=>numeric]
    // $aName, $bName, $aClass, $bClass, $unit
    $max = 0;
    foreach ($rows as $r) { $max = max($max, (float) $r['a'], (float) $r['b']); }
    $max = $max > 0 ? $max : 1;
@endphp
<div>
    <div class="mb-3 flex items-center gap-4 text-xs text-slate-500">
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded {{ $aClass }}"></span>{{ $aName }}</span>
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded {{ $bClass }}"></span>{{ $bName }}</span>
    </div>
    <div class="flex items-end gap-3 overflow-x-auto" style="height: 180px">
        @foreach ($rows as $r)
            <div class="flex min-w-[3rem] flex-1 flex-col items-center justify-end gap-1">
                <div class="flex w-full items-end justify-center gap-1" style="height: 150px">
                    <div class="w-1/2 rounded-t {{ $aClass }}" style="height: {{ max(2, (float) $r['a'] / $max * 100) }}%" title="{{ $aName }}: {{ number_format((float) $r['a'], 2) }}"></div>
                    <div class="w-1/2 rounded-t {{ $bClass }}" style="height: {{ max(2, (float) $r['b'] / $max * 100) }}%" title="{{ $bName }}: {{ number_format((float) $r['b'], 2) }}"></div>
                </div>
                <span class="whitespace-nowrap text-[10px] text-slate-400">{{ $r['label'] }}</span>
            </div>
        @endforeach
    </div>
</div>
