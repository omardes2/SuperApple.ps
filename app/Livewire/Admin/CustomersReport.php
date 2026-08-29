<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\ExportsCsv;
use App\Services\ReportsService;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تقارير العملاء')]
class CustomersReport extends Component
{
    use ExportsCsv;

    public function mount(): void
    {
        $this->authorize('reports.customers');
    }

    public function export(ReportsService $reports)
    {
        $this->authorize('reports.export');
        $rows = [];
        foreach ($reports->topCustomersByOutstanding(100) as $r) {
            $rows[] = [$r['customer']->name, $r['amount']];
        }

        return $this->streamCsv('customers-outstanding.csv', ['العميل', 'المستحق USD'], $rows);
    }

    public function render(ReportsService $reports)
    {
        // Revenue is finance-only; show it only to users who may see finance.
        $canFinance = auth()->user()->can('reports.financial') || auth()->user()->can('reports.ar_aging');

        return view('livewire.admin.customers-report', [
            'byRevenue' => $canFinance ? $reports->topCustomersByRevenue(10) : [],
            'byOutstanding' => $canFinance ? $reports->topCustomersByOutstanding(10) : [],
            'byPayments' => $canFinance ? $reports->topCustomersByPayments(10) : [],
            'byProjects' => $reports->topCustomersByActiveProjects(10),
            'canFinance' => $canFinance,
            'canExport' => auth()->user()->can('reports.export'),
            'fmt' => Format::class,
        ]);
    }
}
