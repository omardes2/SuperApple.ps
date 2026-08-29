<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ExportsCsv;
use App\Services\ReportsService;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('أعمار الذمم المدينة')]
class ArAgingReport extends Component
{
    use ExportsCsv;

    public string $asOf = '';

    public function mount(): void
    {
        $this->authorize('reports.ar_aging');
        $this->asOf = now()->toDateString();
    }

    public function export(ReportsService $reports)
    {
        $this->authorize('reports.export');
        $data = $reports->arAging($this->asOf);
        $rows = [];
        foreach ($data['rows'] as $r) {
            $rows[] = [
                $r['customer']?->name,
                $r['invoices'],
                $r['remaining_usd'],
                $r['oldest_due'] ?? '',
                $r['max_days_overdue'],
            ];
        }

        return $this->streamCsv('ar-aging-'.$this->asOf.'.csv',
            ['العميل', 'عدد الفواتير', 'المتبقي USD', 'أقدم استحقاق', 'أيام التأخر'], $rows);
    }

    public function render(ReportsService $reports)
    {
        return view('livewire.admin.ar-aging-report', [
            'data' => $reports->arAging($this->asOf),
            'fmt' => Format::class,
            'canExport' => auth()->user()->can('reports.export'),
        ]);
    }
}
