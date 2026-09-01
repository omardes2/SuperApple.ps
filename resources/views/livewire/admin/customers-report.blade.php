<div>
    <x-page-header title="تقارير العملاء" subtitle="أعلى العملاء حسب الإيراد والمستحقات والدفعات والمشاريع">
        <x-slot:actions>
            @if ($canExport && $canFinance)<button wire:click="export" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">تصدير المستحقات CSV</button>@endif
            <a href="{{ route('admin.reports') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600">مركز التقارير</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($canFinance)
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">الأعلى إيراداً (من دفتر الأستاذ — ₪)</h3>
                @include('livewire.admin.partials.report-rank', ['rows' => $byRevenue, 'value' => fn($r) => $fmt::ils($r['amount']), 'label' => fn($r) => $r['customer']?->name])
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">الأعلى مستحقات (USD)</h3>
                @include('livewire.admin.partials.report-rank', ['rows' => $byOutstanding, 'value' => fn($r) => isset($r['amount_ils']) ? $fmt::ils($r['amount_ils']).' · '.$fmt::usd($r['amount']) : $fmt::usd($r['amount']), 'label' => fn($r) => $r['customer']?->name])
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="mb-3 font-semibold text-slate-800">الأعلى دفعات (USD)</h3>
                @include('livewire.admin.partials.report-rank', ['rows' => $byPayments, 'value' => fn($r) => isset($r['amount_ils']) ? $fmt::ils($r['amount_ils']).' · '.$fmt::usd($r['amount']) : $fmt::usd($r['amount']), 'label' => fn($r) => $r['customer']?->name])
            </div>
        @endif
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="mb-3 font-semibold text-slate-800">الأكثر مشاريع نشطة</h3>
            @include('livewire.admin.partials.report-rank', ['rows' => $byProjects, 'value' => fn($r) => $r['count'], 'label' => fn($r) => $r['customer']?->name])
        </div>
    </div>
</div>
